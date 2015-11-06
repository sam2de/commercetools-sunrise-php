<?php
/**
 * @author @ct-jensschulze <jens.schulze@commercetools.de>
 */

namespace Commercetools\Sunrise\Controller;

use Commercetools\Commons\Helper\PriceFinder;
use Commercetools\Core\Cache\CacheAdapterInterface;
use Commercetools\Core\Client;
use Commercetools\Core\Model\Common\Attribute;
use Commercetools\Core\Model\Product\Facet;
use Commercetools\Core\Model\Product\FacetResultCollection;
use Commercetools\Core\Model\Product\Product;
use Commercetools\Core\Model\Product\ProductProjection;
use Commercetools\Core\Model\Product\ProductProjectionCollection;
use Commercetools\Core\Model\Product\ProductVariant;
use Commercetools\Core\Model\ProductType\ProductType;
use Commercetools\Core\Response\PagedSearchResponse;
use Commercetools\Sunrise\Model\Config;
use Commercetools\Sunrise\Model\Repository\CategoryRepository;
use Commercetools\Sunrise\Model\Repository\ProductRepository;
use Commercetools\Sunrise\Model\ViewData;
use Commercetools\Sunrise\Model\ViewDataCollection;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\UriInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Translation\TranslatorInterface;

class CatalogController extends SunriseController
{
    const SLUG_SKU_SEPARATOR = '--';

    /**
     * @var ProductRepository
     */
    protected $productRepository;

    /**
     * @var FacetResultCollection
     */
    protected $facets;

    public function __construct(
        Client $client,
        $locale,
        UrlGenerator $generator,
        CacheAdapterInterface $cache,
        TranslatorInterface $translator,
        Config $config,
        Session $session,
        CategoryRepository $categoryRepository,
        ProductRepository $productRepository
    )
    {
        parent::__construct($client, $locale, $generator, $cache, $translator, $config, $session, $categoryRepository);
        $this->productRepository = $productRepository;
    }


    public function home(Request $request)
    {
        $viewData = $this->getViewData('Sunrise - Home')->toArray();

        return ['home', $viewData];
    }

    public function search(Request $request)
    {
        $uri = new Uri($request->getRequestUri());
        $products = $this->getProducts($request);

        $viewData = $this->getViewData('Sunrise - ProductRepository Overview Page');

        $viewData->content = new ViewData();
        $viewData->content->text = "Women";
        $viewData->content->banner = new ViewData();
        $viewData->content->banner->text = "Women";
        $viewData->content->banner->description = "Lorem dolor deserunt debitis voluptatibus odio id animi voluptates alias eum adipisci laudantium iusto totam quibusdam modi quo! Consectetur.";
        $viewData->content->banner->imageMobile = "/assets/img/banner_mobile.jpg";
        $viewData->content->banner->imageDesktop = "/assets/img/banner_desktop.jpg";
        $viewData->jumboTron = new ViewData();
        $viewData->content->products = new ViewData();
        $viewData->content->products->list = new ViewDataCollection();
        $viewData->content->static = $this->getStaticContent();
        $viewData->content->display = $this->getDisplayContent($this->getItemsPerPage($request));
        $viewData->content->filters = $this->getFiltersData($uri);
        $viewData->content->sort = $this->getSortData($this->getSort($request, 'sunrise.products.sort'));
        foreach ($products as $key => $product) {
            $viewData->content->products->list->add($this->getProductData($product, $product->getMasterVariant()));
        }
        $viewData->content->pagination = $this->pagination;
        /**
         * @var callable $renderer
         */
        return ['product-overview', $viewData->toArray()];
    }

    protected function getFiltersData(UriInterface $uri)
    {
        $filter = new ViewData();
        $filter->url = $uri->getPath();
        $filter->list = new ViewDataCollection();
        $filter->list->add($this->getCategoriesFacet());

        return $filter;
    }

    protected function addToCollection($categoryTree, ViewDataCollection $collection, $ancestors, $categoryId, ViewData $entry)
    {
        if (!empty($ancestors)) {
            $firstAncestor = array_shift($ancestors);
            $firstAncestorEntry = $categoryTree[$firstAncestor];

            $ancestor = $collection->getAt($firstAncestor);
            if (is_null($ancestor)) {
                $firstAncestorEntry->children = new ViewDataCollection();
                $collection->add($firstAncestorEntry, $firstAncestor);
            }
            if (!isset($ancestor->children)) {
                $firstAncestorEntry->children = new ViewDataCollection();
            }
            $this->addToCollection($categoryTree, $firstAncestorEntry->children, $ancestors, $categoryId, $entry);
        } else {
            $collection->add($entry, $categoryId);
        }
    }

    protected function getCategoriesFacet()
    {
        $maxDepth = 1;
        $categoryFacet = $this->facets->getByName('categories');
        $categoryData = $this->getCategories();

        $cacheKey = 'category-facet-tree-' . $this->locale;
        if (!$this->cache->has($cacheKey)) {
            $categoryTree = [];
            foreach ($categoryData as $category) {
                $categoryEntry = new ViewData();
                $categoryEntry->value = $category->getId();
                $categoryEntry->label = (string)$category->getName();
                $ancestors = $category->getAncestors();
                $categoryEntry->ancestors = [];
                if (!is_null($ancestors)) {
                    foreach ($ancestors as $ancestor) {
                        $categoryEntry->ancestors[] = $ancestor->getId();
                    }
                }
                $categoryTree[$category->getId()] = $categoryEntry;
            }
            $this->cache->store($cacheKey, serialize($categoryTree));
        } else {
            $categoryTree = unserialize($this->cache->fetch($cacheKey));
        }

        $limitedOptions = new ViewDataCollection();

        foreach ($categoryFacet->getTerms() as $term) {
            $categoryId = $term->getTerm();
            $categoryEntry = $categoryTree[$categoryId];
            if (count($categoryEntry->ancestors) > $maxDepth) {
                continue;
            }
            $categoryEntry->count = $term->getCount();
            $this->addToCollection($categoryTree, $limitedOptions, $categoryEntry->ancestors, $categoryId, $categoryEntry);
        }

        $categories = new ViewData();
        $categories->facet = new ViewData();
        $categories->facet->available = true;
        $categories->hierarchicalSelectFacet = true;
        $categories->facet->key = 'product-type';
        $categories->facet->label = $this->trans('search.filters.productType');
        $categories->facet->available = true;
        $categories->facet->limitedOptions = $limitedOptions;

        return $categories;
    }

    protected function getSortData($currentSort)
    {
        $sortData = new ViewDataCollection();

        foreach ($this->config['sunrise.products.sort'] as $sort) {
            $entry = new ViewData();
            $entry->value = $sort['formValue'];
            $entry->label = $this->trans('search.sort.' . $sort['formValue']);
            if ($currentSort == $sort) {
                $entry->selected = true;
            }
            $sortData->add($entry);
        }

        return $sortData;
    }

    protected function getStaticContent()
    {
        $static = new ViewData();
        $static->productCountSeparatorText = $this->trans('filter.productCountSeparator');
        $static->displaySelectorText = $this->trans('filter.itemsPerPage');
        $static->saleText = $this->trans('product.saleText');
        $static->productDetailsText = $this->trans('product.detailsText');
        $static->deliveryAndReturnsText = $this->trans('product.deliveryReturnsText');
        $static->standardDeliveryText = $this->trans('product.standardDeliveryText');
        $static->expressDeliveryText = $this->trans('product.expressDeliveryText');
        $static->freeReturnsText = $this->trans('product.freeReturnsText');
        $static->moreDeliveryInfoText = $this->trans('product.moreDeliveryInfoText');
        $static->sizeDefaultItem = new ViewData();
        $static->sizeDefaultItem->text = $this->trans('product.sizeDefaultItem');
        $static->sizeDefaultItem->selected = empty($sku);
        $static->sizeDefaultItem->id = "pdp-size-select-first-option";

        return $static;
    }

    protected function getDisplayContent($currentCount)
    {
        $display = new ViewDataCollection();

        foreach ($this->config->get('sunrise.itemsPerPage') as $count) {
            $entry = new ViewData();
            $entry->value = $count;
            $entry->text = $count;
            if ($currentCount == $count) {
                $entry->selected = true;
            }
            $display->add($entry);
        }

        return $display;
    }

    public function detail(Request $request)
    {
        $slug = $request->get('slug');
        $sku = $request->get('sku');

        $viewData = $this->getViewData('Sunrise - ProductRepository Detail Page');

        $viewData->content->static = $this->getStaticContent();
        $product = $this->productRepository->getProductBySlug($slug);
        $productData = $this->getProductDetailData($product, $sku);
        $viewData->content->product = $productData;

        return ['product-detail', $viewData->toArray()];
    }

    protected function getProductData(ProductProjection $product, ProductVariant $productVariant, $selectSku = null)
    {
        $productModel = new ViewData();

        $price = PriceFinder::findPriceFor($productVariant->getPrices(), 'EUR');
        $productUrl = $this->generator->generate(
            'pdp',
            [
                'slug' => (string)$product->getSlug(),
                'sku' => $productVariant->getSku()
            ]
        );

        $productModel->url = $productUrl;
        $productModel->addToCartUrl = $this->generator->generate('cartAdd');
        $productModel->addToWishListUrl = '';
        $productModel->addReviewUrl = '';

        $productData = new ViewData();
        $productData->id = $product->getId();
        $productData->variantId = $productVariant->getId();
        $productData->slug = (string)$product->getSlug();
        $productData->sku = $productVariant->getSku();
        $productData->name = (string)$product->getName();
        $productData->description = (string)$product->getDescription();

        $productType = $product->getProductType()->getObj();
        if (!is_null($productType)) {
            list($attributes, $variantKeys, $variantIdentifiers) = $this->getVariantSelectors($product, $productType, $selectSku);
            $productData->variants = $variantKeys;
            $productData->variantIdentifiers = $variantIdentifiers;

            $productData->attributes = $attributes;
        }

        if (!is_null($price->getDiscounted())) {
            $productData->price = (string)$price->getDiscounted()->getValue();
            $productData->priceOld = (string)$price->getValue();
        } else {
            $productData->price = (string)$price->getValue();
        }
        $productModel->sale = isset($productData->priceOld);

        $productData->gallery = new ViewData();
        $productData->gallery->mainImage = (string)$productVariant->getImages()->getAt(0)->getUrl();
        $productData->gallery->list = new ViewDataCollection();
        foreach ($productVariant->getImages() as $image) {
            $imageData = new ViewData();
            $imageData->thumbImage = $image->getUrl();
            $imageData->bigImage = $image->getUrl();
            $productData->gallery->list->add($imageData);
        }
        $productModel->data = $productData;

        return $productModel;
    }

    protected function getProductDetailData(ProductProjection $product, $sku)
    {
        $requestSku = $sku;
        if (empty($sku)) {
            $sku = $product->getMasterVariant()->getSku();
        }

        $productVariant = $product->getVariantBySku($sku);
        if (empty($productVariant)) {
            throw new NotFoundHttpException("resource not found");
        }

        $productModel = $this->getProductData($product, $productVariant, $requestSku);

        $productModel->details = new ViewData();
        $productModel->details->list = new ViewDataCollection();
        $productVariant->getAttributes()->setAttributeDefinitions(
            $product->getProductType()->getObj()->getAttributes()
        );
        $attributeList = $this->config['sunrise.products.details.attributes'];
        foreach ($attributeList as $attributeName) {
            $attribute = $productVariant->getAttributes()->getByName($attributeName);
            $attributeDefinition = $product->getProductType()->getObj()->getAttributes()->getByName(
                $attributeName
            );
            $attributeData = new ViewData();
            $attributeData->text = (string)$attributeDefinition->getLabel() . ': ' . (string)$attribute->getValue();
            $productModel->details->list->add($attributeData);
        }
        return $productModel;
    }

    public function getVariantSelectors(ProductProjection $product, ProductType $productType, $sku)
    {
        $variantSelectors = $this->config['sunrise.products.variantsSelector'][$productType->getName()];
        $variants = [];
        $attributes = [];
        /**
         * @var ProductVariant $variant
         */
        foreach ($product->getAllVariants() as $variant) {
            $variantId = $variant->getId();
            $variant->getAttributes()->setAttributeDefinitions($productType->getAttributes());
            $selected = ($sku == $variant->getSku());
            foreach ($variantSelectors as $attributeName) {
                $attribute = $variant->getAttributes()->getByName($attributeName);
                if ($attribute) {
                    $value = (string)$attribute->getValue();
                    $variants[$variantId][$attributeName] = $value;
                    if (!isset($attributes[$attributeName])) {
                        $attributes[$attributeName] = [
                            'key' => $attributeName,
                            'name' => (string)$attribute->getName(),
                        ];
                    }
                    if (!isset($attributes[$attributeName]['list'][$value])) {
                        $attributes[$attributeName]['list'][$value] = [
                            'text' => $value,
                            'value' => $value,
                            'selected' => $selected
                        ];
                    }
                }
            }
        }

        $variantKeys = [];
        foreach ($variants as $variantId => $variantAttributes) {
            foreach ($variantSelectors as $selectorX) {
                foreach ($variantSelectors as $selectorY) {
                    if ($selectorX == $selectorY) {
                        continue;
                    }
                    if (isset($variantAttributes[$selectorX]) && isset($variantAttributes[$selectorY])) {
                        $valueX = $variantAttributes[$selectorX];
                        $valueY = $variantAttributes[$selectorY];
                        if (
                            isset($attributes[$selectorX]['selectData'][$valueX][$selectorY]) &&
                            in_array($valueY, $attributes[$selectorX]['selectData'][$valueX][$selectorY])
                        ) {
                            continue;
                        }
                        $attributes[$selectorX]['selectData'][$valueX][$selectorY][] = $valueY;
                    }
                }
            }
            if (count($variantAttributes) == count($variantSelectors)) {
                $variantKey = implode('-', $variantAttributes);
                $variantKeys[$variantKey] = $variantId;
            }
        }

        return [$attributes, $variantKeys, $variantSelectors];
    }

    protected function getProducts(Request $request)
    {
        $itemsPerPage = $this->getItemsPerPage($request);
        $currentPage = $this->getCurrentPage($request);
        $sort = $this->getSort($request, 'sunrise.products.sort')['searchParam'];
        $category = $request->get('category');

        $facetDefinitions = [
            Facet::of()->setName('categories.id')->setAlias('categories')
        ];
        /**
         * @var ProductProjectionCollection $products
         * @var PagedSearchResponse $response
         */
        list($products, $facets, $offset, $total) = $this->productRepository->getProducts(
            $this->getCategories(),
            $this->locale,
            $itemsPerPage,
            $currentPage,
            $sort,
            $category,
            $facetDefinitions
        );

        $this->applyPagination(new Uri($request->getRequestUri()), $offset, $total, $itemsPerPage);
        $this->pagination->productsCount = $products->count();
        $this->pagination->totalProducts = $total;
        $this->facets = $facets;

        return $products;
    }

    /**
     * @param Attribute $attribute
     * @param $variantSku
     * @param $productSlug
     * @param $sku
     * @param $idPrefix
     * @return ViewData
     */
    protected function getSelectorData(Attribute $attribute, $variantSku, $productSlug, $sku, $idPrefix)
    {
        $size = new ViewData();
        $size->id = $idPrefix . '-' . (string)$attribute->getValue();
        $size->text = (string)$attribute->getValue();
        $url = $this->getLinkFor(
            'pdp',
            ['slug' => $productSlug, 'sku' => $variantSku]
        );
        $size->value = $url;
        $size->selected = ($variantSku == $sku);

        return $size;
    }
}

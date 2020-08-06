<?php

declare(strict_types=1);

namespace Signativa\ExtendedBundle\Plugin\Bundle\Model;

use Magento\Bundle\Api\Data\LinkInterface;
use Magento\Bundle\Model\ResourceModel\BundleFactory;
use Magento\Bundle\Model\ResourceModel\Option\CollectionFactory;
use Magento\Bundle\Model\Selection;
use Magento\Bundle\Model\SelectionFactory;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Type;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\InputException;
use Signativa\ExtendedBundle\Helper\System;

class LinkManagement
{

    /**
     * @var System
     */
    private $system;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var SelectionFactory
     */
    private $bundleSelection;

    /**
     * @var MetadataPool
     */
    private $metadataPool;

    /**
     * @var CollectionFactory
     */
    private $optionCollection;

    /**
     * @var BundleFactory
     */
    private $bundleFactory;

    public function __construct(
        System $system,
        ProductRepositoryInterface $productRepository,
        SelectionFactory $bundleSelection,
        CollectionFactory $optionCollection,
        BundleFactory $bundleFactory
    ) {
        $this->system = $system;
        $this->productRepository = $productRepository;
        $this->bundleSelection = $bundleSelection;
        $this->optionCollection = $optionCollection;
        $this->bundleFactory = $bundleFactory;
    }

    public function aroundSaveChild(
        \Magento\Bundle\Model\LinkManagement $subject,
        \Closure $proceed,
        $sku,
        $linkedProduct
    ) {
        if (!$this->system->isEnabled()) {
            return $proceed($sku, $linkedProduct);
        }
        return $this->saveChild($sku,$linkedProduct);
    }

    /**
     * {@inheritdoc}
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function aroundAddChild(
        \Magento\Bundle\Model\LinkManagement $subject,
        \Closure $proceed,
        ProductInterface $product,
        $optionId,
        LinkInterface $linkedProduct
    ) {
        if (!$this->system->isEnabled()) {
            return $proceed($product, $optionId, $linkedProduct);
        }
        return $this->addChild($product,$optionId,$linkedProduct);
    }

    /**
     * {@inheritdoc}
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function saveChild(
        $sku,
        LinkInterface $linkedProduct
    ) {
        $product = $this->productRepository->get($sku, true);
        if ($product->getTypeId() != Type::TYPE_BUNDLE) {
            throw new InputException(
                __('The product with the "%1" SKU isn\'t a bundle product.', [$product->getSku()])
            );
        }

        /** @var \Magento\Catalog\Model\Product $linkProductModel */
        $linkProductModel = $this->productRepository->get($linkedProduct->getSku());
        if ($linkProductModel->getTypeId() !== Configurable::TYPE_CODE) {
            if ($linkProductModel->isComposite()) {
                throw new InputException(__('The bundle product can\'t contain another composite product.'));
            }
        }

        if (!$linkedProduct->getId()) {
            throw new InputException(__('The product link needs an ID field entered. Enter and try again.'));
        }

        /** @var Selection $selectionModel */
        $selectionModel = $this->bundleSelection->create();
        $selectionModel->load($linkedProduct->getId());
        if (!$selectionModel->getId()) {
            throw new InputException(
                __(
                    'The product link with the "%1" ID field wasn\'t found. Verify the ID and try again.',
                    [$linkedProduct->getId()]
                )
            );
        }
        $linkField = $this->getMetadataPool()->getMetadata(ProductInterface::class)->getLinkField();
        $selectionModel = $this->mapProductLinkToSelectionModel(
            $selectionModel,
            $linkedProduct,
            $linkProductModel->getId(),
            $product->getData($linkField)
        );

        try {
            $selectionModel->save();
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Could not save child: "%1"', $e->getMessage()), $e);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function addChild(
        ProductInterface $product,
        $optionId,
        LinkInterface $linkedProduct
    ) {
        if ($product->getTypeId() != Type::TYPE_BUNDLE) {
            throw new InputException(
                __('The product with the "%1" SKU isn\'t a bundle product.', $product->getSku())
            );
        }

        $linkField = $this->getMetadataPool()->getMetadata(ProductInterface::class)->getLinkField();

        $options = $this->optionCollection->create();

        $options->setIdFilter($optionId);
        $options->setProductLinkFilter($product->getData($linkField));

        $existingOption = $options->getFirstItem();

        if (!$existingOption->getId()) {
            throw new InputException(
                __(
                    'Product with specified sku: "%1" does not contain option: "%2"',
                    [$product->getSku(), $optionId]
                )
            );
        }

        /* @var $resource \Magento\Bundle\Model\ResourceModel\Bundle */
        $resource = $this->bundleFactory->create();
        $selections = $resource->getSelectionsData($product->getData($linkField));
        /** @var \Magento\Catalog\Model\Product $linkProductModel */
        $linkProductModel = $this->productRepository->get($linkedProduct->getSku());
        if ($linkProductModel->getTypeId() !== Configurable::TYPE_CODE) {
            if ($linkProductModel->isComposite()) {
                throw new InputException(__('The bundle product can\'t contain another composite product.'));
            }
        }

        if ($selections) {
            foreach ($selections as $selection) {
                if ($selection['option_id'] == $optionId &&
                    $selection['product_id'] == $linkProductModel->getEntityId() &&
                    $selection['parent_product_id'] == $product->getData($linkField)) {
                    if (!$product->getCopyFromView()) {
                        throw new CouldNotSaveException(
                            __(
                                'Child with specified sku: "%1" already assigned to product: "%2"',
                                [$linkedProduct->getSku(), $product->getSku()]
                            )
                        );
                    } else {
                        return $this->bundleSelection->create()->load($linkProductModel->getEntityId());
                    }
                }
            }
        }

        $selectionModel = $this->bundleSelection->create();
        $selectionModel = $this->mapProductLinkToSelectionModel(
            $selectionModel,
            $linkedProduct,
            $linkProductModel->getEntityId(),
            $product->getData($linkField)
        );
        $selectionModel->setOptionId($optionId);

        try {
            $selectionModel->save();
            $resource->addProductRelation($product->getData($linkField), $linkProductModel->getEntityId());
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Could not save child: "%1"', $e->getMessage()), $e);
        }

        return $selectionModel->getId();
    }

    /**
     * Get MetadataPool instance
     * @return MetadataPool
     */
    private function getMetadataPool()
    {
        if (!$this->metadataPool) {
            $this->metadataPool = ObjectManager::getInstance()->get(MetadataPool::class);
        }
        return $this->metadataPool;
    }

    /**
     * @param Selection $selectionModel
     * @param LinkInterface $productLink
     * @param string $linkedProductId
     * @param string $parentProductId
     * @return Selection
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    protected function mapProductLinkToSelectionModel(
        Selection $selectionModel,
        LinkInterface $productLink,
        $linkedProductId,
        $parentProductId
    ) {
        $selectionModel->setProductId($linkedProductId);
        $selectionModel->setParentProductId($parentProductId);
        if ($productLink->getSelectionId() !== null) {
            $selectionModel->setSelectionId($productLink->getSelectionId());
        }
        if ($productLink->getOptionId() !== null) {
            $selectionModel->setOptionId($productLink->getOptionId());
        }
        if ($productLink->getPosition() !== null) {
            $selectionModel->setPosition($productLink->getPosition());
        }
        if ($productLink->getQty() !== null) {
            $selectionModel->setSelectionQty($productLink->getQty());
        }
        if ($productLink->getPriceType() !== null) {
            $selectionModel->setSelectionPriceType($productLink->getPriceType());
        }
        if ($productLink->getPrice() !== null) {
            $selectionModel->setSelectionPriceValue($productLink->getPrice());
        }
        if ($productLink->getCanChangeQuantity() !== null) {
            $selectionModel->setSelectionCanChangeQty($productLink->getCanChangeQuantity());
        }
        if ($productLink->getIsDefault() !== null) {
            $selectionModel->setIsDefault($productLink->getIsDefault());
        }

        return $selectionModel;
    }
}

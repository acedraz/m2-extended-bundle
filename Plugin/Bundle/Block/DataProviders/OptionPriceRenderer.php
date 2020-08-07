<?php

declare(strict_types=1);

namespace Signativa\ExtendedBundle\Plugin\Bundle\Block\DataProviders;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Pricing\Price\TierPrice;
use Magento\Framework\Pricing\Render;
use Magento\Framework\View\LayoutInterface;
use Signativa\ExtendedBundle\Helper\System;

class OptionPriceRenderer
{

    /**
     * @var System
     */
    private $system;

    /**
     * @var LayoutInterface
     */
    private $layout;

    public function __construct(
        System $system,
        LayoutInterface $layout
    ) {
        $this->system = $system;
        $this->layout = $layout;
    }

    public function aroundRenderTierPrice(
        \Magento\Bundle\Block\DataProviders\OptionPriceRenderer $subject,
        \Closure $proceed,
        Product $selection,
        array $arguments = []
    ) {
        if (!$this->system->isEnabled()) {
            return $proceed($selection,$arguments);;
        }
        return $this->renderTierPrice($selection,$arguments);
    }

    /**
     * Format tier price string
     *
     * @param Product $selection
     * @param array $arguments
     * @return string
     */
    public function renderTierPrice(Product $selection, array $arguments = []): string
    {
        if (!array_key_exists('zone', $arguments)) {
            $arguments['zone'] = Render::ZONE_ITEM_OPTION;
        }
        $priceHtml = '';
        /** @var Render $priceRender */
        $priceRender = $this->layout->getBlock('product.price.render.default');
        if ($priceRender !== false) {
            $priceHtml = $priceRender->render(
                TierPrice::PRICE_CODE,
                $selection,
                $arguments
            );
        }
        if (is_null($priceHtml)) {
            return '';
        }
        return $priceHtml;
    }
}

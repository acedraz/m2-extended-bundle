<?php

declare(strict_types=1);

namespace Signativa\ExtendedBundle\Plugin\Bundle\Helper;

use Signativa\ExtendedBundle\Helper\System;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;

/**
 * Class Data
 */
class Data
{

    /**
     * @var System
     */
    private $system;

    public function __construct(
        System $system
    ) {
        $this->system = $system;
    }

    /**
     * @param \Magento\Bundle\Helper\Data $subject
     * @param $result
     * @return mixed
     */
    public function afterGetAllowedSelectionTypes(
        \Magento\Bundle\Helper\Data $subject,
        $result
    ) {
        if (!$this->system->isEnabled()) {
            return $result;
        }
        if (!empty($result)) {
            $result[Configurable::TYPE_CODE] = Configurable::TYPE_CODE;
        }
        return $result;
    }
}

<?php

declare(strict_types=1);

namespace Signativa\ExtendedBundle\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Store\Model\ScopeInterface;

/**
 * Class System
 */
class System extends AbstractHelper
{
    /**
     * @return mixed
     */
    public function isEnabled() : bool
    {
        return (bool)$this->scopeConfig->getValue(
            Config::SYSTEM_GENERAL_ENABLE,
            ScopeInterface::SCOPE_STORE
        );
    }
}

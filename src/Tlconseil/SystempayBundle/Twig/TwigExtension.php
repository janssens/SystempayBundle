<?php

namespace Tlconseil\SystempayBundle\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Class TwigExtension
 * @package Tlconseil\SystempayBundle\Twig
 */
class TwigExtension extends AbstractExtension
{
    /**
     * @return array
     */
    public function getFunctions()
    {
        return array(
            new TwigFunction('systempayForm', array($this, 'systempayForm')),
        );
    }

    /**
     * @param $fields
     * @return string
     */
    public function systempayForm($fields)
    {
        $inputs = '';
        foreach ($fields as $field => $value)
            $inputs .= sprintf('<input type="hidden" name="%s" value="%s">', $field, $value);
        return $inputs;
    }

}
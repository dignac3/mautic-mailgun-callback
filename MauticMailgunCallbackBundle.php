<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMailgunCallbackBundle;

use Mautic\PluginBundle\Bundle\PluginBundleBase;

class MauticMailgunCallbackBundle extends PluginBundleBase
{
        public const SUPPORTED_MAILER_SCHEMES = [
            'mailgun+smtp',
            'mailgun+api',
            'mailgun+https',
        ];
}


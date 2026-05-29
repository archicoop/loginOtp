<?php

namespace APP\plugins\generic\loginOtp\classes;

use APP\plugins\generic\loginOtp\LoginOtpPlugin;
use PKP\form\Form;
use PKP\security\Role;

class LoginOtpSettingsForm extends Form
{
    // Roles available in OMP and OJS (defined in pkp-lib) — role_id => locale key
    public const ROLES = [
        Role::ROLE_ID_SITE_ADMIN          => 'plugins.generic.loginOtp.settings.role.siteAdmin',
        Role::ROLE_ID_MANAGER             => 'plugins.generic.loginOtp.settings.role.manager',
        Role::ROLE_ID_SUB_EDITOR          => 'plugins.generic.loginOtp.settings.role.subEditor',
        Role::ROLE_ID_REVIEWER            => 'plugins.generic.loginOtp.settings.role.reviewer',
        Role::ROLE_ID_ASSISTANT           => 'plugins.generic.loginOtp.settings.role.assistant',
        Role::ROLE_ID_AUTHOR              => 'plugins.generic.loginOtp.settings.role.author',
        Role::ROLE_ID_READER              => 'plugins.generic.loginOtp.settings.role.reader',
        Role::ROLE_ID_SUBSCRIPTION_MANAGER => 'plugins.generic.loginOtp.settings.role.subscriptionManager',
    ];

    public function __construct(
        private LoginOtpPlugin $plugin,
        private int            $contextId
    ) {
        parent::__construct($plugin->getTemplateResource('settings.tpl'));
    }

    public function initData(): void
    {
        $saved = $this->plugin->getSetting($this->contextId, 'requiredRoles');
        // null = not configured yet → require 2FA for all roles (backward compatibility)
        $this->setData('requiredRoles', $saved ?? array_keys(self::ROLES));
        $this->setData('allRoles', self::ROLES);
        $this->setData('pluginName', $this->plugin->getName());
    }

    public function readInputData(): void
    {
        $this->readUserVars(['requiredRoles']);
    }

    public function execute(...$functionArgs): void
    {
        $roles = array_map('intval', (array) ($this->getData('requiredRoles') ?? []));
        $this->plugin->updateSetting($this->contextId, 'requiredRoles', $roles, 'object');
        parent::execute(...$functionArgs);
    }
}

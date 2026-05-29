<?php

namespace APP\plugins\generic\twoFactorAuth\classes;

use APP\plugins\generic\twoFactorAuth\TwoFactorAuthPlugin;
use PKP\form\Form;
use PKP\security\Role;

class TwoFactorAuthSettingsForm extends Form
{
    // Roles available in OMP and OJS (defined in pkp-lib) — role_id => locale key
    public const ROLES = [
        Role::ROLE_ID_SITE_ADMIN           => 'plugins.generic.twoFactorAuth.settings.role.siteAdmin',
        Role::ROLE_ID_MANAGER              => 'plugins.generic.twoFactorAuth.settings.role.manager',
        Role::ROLE_ID_SUB_EDITOR           => 'plugins.generic.twoFactorAuth.settings.role.subEditor',
        Role::ROLE_ID_REVIEWER             => 'plugins.generic.twoFactorAuth.settings.role.reviewer',
        Role::ROLE_ID_ASSISTANT            => 'plugins.generic.twoFactorAuth.settings.role.assistant',
        Role::ROLE_ID_AUTHOR               => 'plugins.generic.twoFactorAuth.settings.role.author',
        Role::ROLE_ID_READER               => 'plugins.generic.twoFactorAuth.settings.role.reader',
        Role::ROLE_ID_SUBSCRIPTION_MANAGER => 'plugins.generic.twoFactorAuth.settings.role.subscriptionManager',
    ];

    // Named roles shown in OMP/OJS UI that map to each role_id — role_id => locale key
    public const ROLE_NOTES = [
        Role::ROLE_ID_MANAGER              => 'plugins.generic.twoFactorAuth.settings.role.manager.note',
        Role::ROLE_ID_SUB_EDITOR           => 'plugins.generic.twoFactorAuth.settings.role.subEditor.note',
        Role::ROLE_ID_REVIEWER             => 'plugins.generic.twoFactorAuth.settings.role.reviewer.note',
        Role::ROLE_ID_ASSISTANT            => 'plugins.generic.twoFactorAuth.settings.role.assistant.note',
        Role::ROLE_ID_AUTHOR               => 'plugins.generic.twoFactorAuth.settings.role.author.note',
        Role::ROLE_ID_SUBSCRIPTION_MANAGER => 'plugins.generic.twoFactorAuth.settings.role.subscriptionManager.note',
    ];

    public function __construct(
        private TwoFactorAuthPlugin $plugin,
        private int $contextId
    ) {
        parent::__construct($plugin->getTemplateResource('settings.tpl'));
    }

    public function initData(): void
    {
        $saved = $this->plugin->getSetting($this->contextId, 'requiredRoles');
        // null = not configured yet → require 2FA for all roles (backward compatibility)
        $this->setData('requiredRoles', $saved ?? array_keys(self::ROLES));
        $this->setData('allRoles', self::ROLES);
        $this->setData('roleNotes', self::ROLE_NOTES);
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

<?php

namespace APP\plugins\generic\loginOtp\classes;

use APP\plugins\generic\loginOtp\LoginOtpPlugin;
use PKP\form\Form;
use PKP\security\Role;
use Illuminate\Support\Facades\DB;

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

    public const ROLE_NOTES = [
        Role::ROLE_ID_MANAGER              => 'plugins.generic.loginOtp.settings.role.manager.note',
        Role::ROLE_ID_SUB_EDITOR           => 'plugins.generic.loginOtp.settings.role.subEditor.note',
        Role::ROLE_ID_REVIEWER             => 'plugins.generic.loginOtp.settings.role.reviewer.note',
        Role::ROLE_ID_ASSISTANT            => 'plugins.generic.loginOtp.settings.role.assistant.note',
        Role::ROLE_ID_AUTHOR               => 'plugins.generic.loginOtp.settings.role.author.note',
        Role::ROLE_ID_SUBSCRIPTION_MANAGER => 'plugins.generic.loginOtp.settings.role.subscriptionManager.note',
    ];

    public function __construct(
        private LoginOtpPlugin $plugin,
        private int            $contextId
    ) {
        parent::__construct($plugin->getTemplateResource('settings.tpl'));
    }

    public function initData(): void
    {
        $row = DB::table('site_settings')
            ->where('setting_name', 'loginOtp::requiredRoles')
            ->first();

        if ($row === null) {
            // Not configured yet → require 2FA for all roles
            $saved = array_keys(self::ROLES);
        } else {
            $saved = json_decode($row->setting_value, true) ?? [];
        }

        $this->setData('requiredRoles', $saved);
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
        $roles = array_map('intval', (array)($this->getData('requiredRoles') ?? []));
        DB::table('site_settings')->updateOrInsert(
            ['setting_name' => 'loginOtp::requiredRoles'],
            ['setting_value' => json_encode($roles), 'locale' => '']
        );
        parent::execute(...$functionArgs);
    }
}

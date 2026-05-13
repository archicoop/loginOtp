<script>
$(function() {ldelim}
    $('#twoFactorAuthSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
{rdelim});
</script>

<form class="pkp_form" id="twoFactorAuthSettingsForm" method="post"
      action="{url router=PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" plugin=$pluginName category="generic" verb="settings" save=true}">
    {csrf}

    {fbvFormSection title="plugins.generic.twoFactorAuth.settings.requiredRoles.title" list=true}
        <p class="pkp_helpers_text">{translate key="plugins.generic.twoFactorAuth.settings.requiredRoles.description"}</p>
        <ul style="list-style:none; padding:0; margin:0.5em 0;">
            {foreach from=$allRoles key=roleId item=roleKey}
            <li style="margin:0.3em 0;">
                <label>
                    <input type="checkbox" name="requiredRoles[]" value="{$roleId}"
                        {if in_array($roleId, $requiredRoles)}checked="checked"{/if}>
                    {translate key=$roleKey}
                </label>
            </li>
            {/foreach}
        </ul>
    {/fbvFormSection}

    {fbvFormButtons}
</form>

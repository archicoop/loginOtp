<script>
	$(function() {ldelim}
		$('#loginOtpSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
		{rdelim});
</script>

<form class="pkp_form" id="loginOtpSettingsForm" method="post"
      action="{url router=PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" plugin=$pluginName category="generic" verb="settings" save=true}">
	{csrf}

	{fbvFormSection title="plugins.generic.loginOtp.settings.requiredRoles.title" list=true}
		<p class="pkp_helpers_text">{translate key="plugins.generic.loginOtp.settings.requiredRoles.description"}</p>
		<ul style="list-style:none; padding:0; margin:0.5em 0;">
			{foreach from=$allRoles key=roleId item=roleKey}
				<li style="margin:0.5em 0;">
					<label style="font-weight:bold;">
						{if in_array($roleId, $alwaysRequiredRoles)}
							<input type="checkbox" checked="checked" disabled="disabled">
						{else}
							<input type="checkbox" name="requiredRoles[]" value="{$roleId}"
							       {if in_array($roleId, $requiredRoles)}checked="checked"{/if}>
						{/if}
						{translate key=$roleKey}
					</label>
					{if in_array($roleId, $alwaysRequiredRoles)}
						<div style="margin-left:1.6em; margin-top:0.1em; font-size:0.85em; color:#666;">
							{translate key="plugins.generic.loginOtp.settings.role.alwaysRequired"}
						</div>
					{/if}
				</li>
			{/foreach}
		</ul>
	{/fbvFormSection}

	{fbvFormButtons}
</form>



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
						<input type="checkbox" name="requiredRoles[]" value="{$roleId}"
						       class="loginOtp-role-checkbox"
						       data-role-id="{$roleId}"
						       {if in_array($roleId, $requiredRoles)}checked="checked"{/if}>
						{translate key=$roleKey}
					</label>
				</li>
			{/foreach}
		</ul>
	{/fbvFormSection}

	{fbvFormButtons}
</form>

<script>
	/**
	 * Role hierarchy cascade — visual only.
	 *
	 * When a role is unchecked, all lower-privilege roles are grayed out
	 * to indicate they are irrelevant (the highest unchecked role exempts
	 * all lower roles). Checkboxes are NOT unchecked or disabled — their
	 * state is preserved in the database on save.
	 *
	 * Before submit, all items are re-enabled to ensure their values
	 * are included in the POST regardless of visual state.
	 */
	$(function() {ldelim}
		var roleHierarchy = [
			{foreach from=$allRoles key=roleId item=roleKey name=roles}
			{$roleId}{if !$smarty.foreach.roles.last},{/if}
			{/foreach}
		];

		var $checkboxes = {ldelim}{rdelim};
		$('.loginOtp-role-checkbox').each(function() {ldelim}
			$checkboxes[$(this).data('role-id')] = $(this);
			{rdelim});

		function applyHierarchy() {ldelim}
			var cascadeOff = false;

			for (var i = 0; i < roleHierarchy.length; i++) {ldelim}
				var roleId = roleHierarchy[i];
				var $cb = $checkboxes[roleId];
				if (!$cb) continue;

				var $li = $cb.closest('li');

				if (cascadeOff) {ldelim}
					$li.css({ldelim} 'opacity': '0.4', 'pointer-events': 'none' {rdelim});
					{rdelim} else {ldelim}
					$li.css({ldelim} 'opacity': '1', 'pointer-events': 'auto' {rdelim});
					{rdelim}

				if (!cascadeOff && !$cb.prop('checked')) {ldelim}
					cascadeOff = true;
					{rdelim}
				{rdelim}
			{rdelim}

		applyHierarchy();

		$('.loginOtp-role-checkbox').on('change', function() {ldelim}
			applyHierarchy();
			{rdelim});

		$('#loginOtpSettingsForm').on('submit', function() {ldelim}
			$('.loginOtp-role-checkbox').closest('li').css({ldelim}
				'opacity': '1',
				'pointer-events': 'auto'
				{rdelim});
			{rdelim});
		{rdelim});
</script>

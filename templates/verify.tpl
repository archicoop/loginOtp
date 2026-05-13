{**
 * templates/verify.tpl
 *
 * Email OTP verification page.
 * Shown after correct username/password — user enters the 6-digit code received by email.
 *}
{include file="frontend/components/header.tpl" pageTitle="plugins.generic.twoFactorAuth.verify.title"}

<style>
.page_2fa_verify {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 2rem 1rem;
}

.tfa-card {
    background: #ffffff;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    box-shadow: 0 2px 12px rgba(0,0,0,.08);
    padding: 2.5rem 2rem;
    width: 100%;
    max-width: 440px;
    margin-top: 1.5rem;
}

.tfa-card h1 {
    font-size: 1.4rem;
    margin: 0 0 0.5rem 0;
    text-align: center;
}

.tfa-card .tfa-icon {
    display: block;
    text-align: center;
    font-size: 2.5rem;
    margin-bottom: 1rem;
    line-height: 1;
}

.tfa-card .tfa-description {
    color: #555;
    font-size: 0.95rem;
    text-align: center;
    margin: 0 0 1.5rem 0;
    line-height: 1.5;
}

.tfa-alert {
    border-radius: 5px;
    padding: 0.75rem 1rem;
    margin-bottom: 1.25rem;
    font-size: 0.9rem;
    line-height: 1.4;
}

.tfa-alert-error {
    background: #fff3f3;
    border-left: 4px solid #c0392b;
    color: #7b1c1c;
}

.tfa-alert-locked {
    background: #fff8e1;
    border-left: 4px solid #e67e22;
    color: #7a4a00;
}

.tfa-code-group {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.4rem;
    margin-bottom: 1.5rem;
}

.tfa-code-group label {
    font-size: 0.85rem;
    font-weight: 600;
    color: #333;
    letter-spacing: 0.03em;
}

.tfa-code-input {
    font-size: 2rem;
    font-family: 'Courier New', Courier, monospace;
    letter-spacing: 0.5em;
    text-align: center;
    width: 10rem;
    padding: 0.5rem 0.75rem;
    border: 2px solid #bbb;
    border-radius: 6px;
    outline: none;
    transition: border-color .2s;
    background: #fafafa;
}

.tfa-code-input:focus {
    border-color: #2980b9;
    background: #fff;
    box-shadow: 0 0 0 3px rgba(41,128,185,.15);
}

.tfa-submit {
    width: 100%;
    padding: 0.75rem;
    font-size: 1rem;
    font-weight: 600;
    border: none;
    border-radius: 6px;
    background: #2980b9;
    color: #fff;
    cursor: pointer;
    transition: background .2s;
    letter-spacing: 0.02em;
}

.tfa-submit:hover {
    background: #1f6391;
}

.tfa-submit:active {
    background: #174e72;
}
</style>

<div class="page page_2fa_verify">
    {include file="frontend/components/breadcrumbs.tpl" currentTitleKey="plugins.generic.twoFactorAuth.verify.title"}

    <div class="tfa-card">
        <span class="tfa-icon" aria-hidden="true">&#128274;</span>
        <h1>{translate key="plugins.generic.twoFactorAuth.verify.title"}</h1>

        {if $isLocked}
            <div class="tfa-alert tfa-alert-locked">
                {translate key="plugins.generic.twoFactorAuth.verify.error.locked"}
            </div>
        {else}
            <p class="tfa-description">{translate key="plugins.generic.twoFactorAuth.verify.description"}</p>

            {if $error}
                <div class="tfa-alert tfa-alert-error">{$error|escape}</div>
            {/if}

            <form method="post" action="{$verifyUrl|escape}">
                {csrf}
                <div class="tfa-code-group">
                    <label for="tfa_code">
                        {translate key="plugins.generic.twoFactorAuth.verify.code.label"}
                    </label>
                    <input
                        type="text"
                        name="code"
                        id="tfa_code"
                        class="tfa-code-input"
                        inputmode="numeric"
                        pattern="[0-9]{ldelim}6{rdelim}"
                        maxlength="6"
                        autocomplete="one-time-code"
                        required
                        autofocus
                        placeholder="{translate key="plugins.generic.twoFactorAuth.verify.code.placeholder"}"
                    >
                </div>
                <button class="tfa-submit" type="submit">
                    {translate key="plugins.generic.twoFactorAuth.verify.submit"}
                </button>
            </form>
        {/if}
    </div>
</div>

{include file="frontend/components/footer.tpl"}

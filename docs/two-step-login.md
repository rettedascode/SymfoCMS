# Two-step login

With two-step login (also called two-factor authentication), a password alone is not enough to get into an account. After the password, the website asks for a six-digit code that only an app on the person's phone can show.

It is optional for every user and can be made mandatory for administrators.

## Switching it on

1. Open your name at the bottom of the sidebar, then **Set up two-step login**.
2. Install an authenticator app on your phone – for example Aegis, Google Authenticator, Microsoft Authenticator or 2FAS – and scan the QR code. If scanning is not possible, type the key shown below the code into the app.
3. Enter the six-digit code the app shows.

You then see ten **backup codes**. Keep them somewhere safe, for example printed out: each one lets you log in once when the phone is not at hand. They are shown only at this moment.

## Logging in

After the password, the website asks for the code. Type the code the app currently shows – or one of the backup codes. Codes change every 30 seconds.

Until the code is entered, nothing of the website can be opened with that login. After five wrong codes there is a pause of a quarter of an hour.

## Managing it

On the same page you can

- create **new backup codes** (the old ones stop working), and
- **switch two-step login off**.

Both ask for your password again, in case somebody else sits at your open browser.

### Lost phone and no backup codes?

An administrator opens *Users* and chooses **Reset two-step login** for the account. The person can then log in with the password alone and set it up again.

If the only administrator is locked out, switch it off for that account on the server:

```bash
php bin/console dbal:run-sql "UPDATE users SET totp_secret = NULL, backup_codes = NULL WHERE email = 'you@example.com'"
```

## Requiring it for administrators

Under *Settings → Security*, tick **Administrators have to use two-step login**. Administrators who have not set it up are taken to the setup page the next time they open the admin area and cannot do anything else before it is done. Editors and authors keep the choice.

## For developers

- Codes follow the TOTP standard (RFC 6238: SHA-1, six digits, 30 seconds), implemented in `App\Security\TwoFactor\Totp`. A code is accepted one step before and after the current time and never twice.
- `App\EventSubscriber\TwoFactorSubscriber` guards the second step. The rule is "closed unless verified": a session of a user with two-step login may only open the code page until it has passed it. That also covers sessions that were open before the setup, and resetting the secret closes all verified sessions.
- Backup codes are stored as keyed hashes; the secret of the authenticator app is stored as it is, because it is needed to check codes. Protect the database and its backups accordingly.
- `APP_SECRET` is part of the hashes: changing it invalidates backup codes and asks everybody for their code again.

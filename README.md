# Custom Account Fields

A WordPress plugin for adding configurable account information and custom verifiable fields. Supports WooCommerce customer account, billing and address fields. 

---

## Features

- **Configurable account fields** — add, remove, reorder, and edit customer fields from the WooCommerce dashboard
- **Admin-only fields** — keep internal fields visible only in the WordPress admin user profile UI
- **Default values** — each field can define a default value applied to new users and existing users missing that meta key
- **JSON defaults/import** — default fields are stored in `config/default-fields.json`, and the dashboard can import a JSON field definition file
- **Multiple field types** — supports text, email, telephone, number, date, textarea, select, and checkbox fields
- **Validation rules** — includes phone, email, URL, postal code, and custom regex validation
- **Required fields** — mark any field as required before the relevant form can be submitted
- **Boolean account flags** — create checkbox fields such as `is_affiliated` or `is_reseller`
- **Per-location display** — choose whether each field appears on registration, account editing, admin user profiles, or any combination

---

## Requirements

| Requirement | Minimum |
|---|---|
| WordPress | 6.0 |
| PHP | 8.0 |
| WooCommerce | Required |

---

## Installation

1. Upload the `custom-account-fields` folder to `/wp-content/plugins/`
2. Activate the plugin through **Plugins → Installed Plugins**
3. Go to **WooCommerce → Account Fields**
4. Configure the fields you want to show on registration, account editing, and admin user profiles
5. Save the field configuration

---

## Managing fields

Go to:

```text
WooCommerce → Account Fields
```

Each field has these main settings:

1. **Key** — the user meta key used to save the value, such as `billing_phone` or `is_affiliated`
2. **Label** — the visible field label
3. **Type** — the field type, such as `text`, `tel`, `select`, or `checkbox`
4. **Default value** — value applied to new users and existing users missing this meta key
5. **Validation** — optional server-side validation rule
6. **Required** — controls whether the field must be filled in enabled form locations
7. **Display in** — controls whether the field appears on registration, account editing, and/or admin user profiles

Fields not enabled for **Registration** or **Account editing** are not rendered on frontend WooCommerce account forms.

---

## Default fields

The plugin loads first-activation defaults from:

```text
config/default-fields.json
```

---

## JSON import

The dashboard can import a JSON file from **WooCommerce → Account Fields**.

The JSON may be either an array of fields or an object with a `fields` array:

```json
{
  "fields": [
    {
      "key": "is_affiliated",
      "label": "Affiliated",
      "type": "checkbox",
      "admin": "1",
      "register": "0",
      "account": "0",
      "default_value": "0"
    }
  ]
}
```

Importing replaces the current field configuration. Existing user values are not overwritten. Missing user values receive the imported default value.

## Recommended keys

Use WooCommerce-compatible keys when the field is meant to be customer data used by WooCommerce:

- `first_name`
- `last_name`
- `billing_phone`

Use project-specific keys for private account flags:

- `is_affiliated`
- `is_reseller`

---

## Theme integration

The plugin outputs frontend fields using WooCommerce form row markup:

```html
<p class="form-row form-row-wide custom-account-field custom-account-field--billing_phone">
```

Themes can target these selectors:

```css
.custom-account-field {
    width: 100%;
}
```

Field sizing and layout should be handled by the active theme.

---

## Translations

The plugin uses the `custom-account-fields` text domain and loads translations from:

```text
languages/
```

Included files:

- `custom-account-fields.pot`
- `custom-account-fields-pt_BR.po`
- `custom-account-fields-pt_BR.mo`

To update translations later, regenerate the POT file from the PHP source and update the `.po` / `.mo` files.

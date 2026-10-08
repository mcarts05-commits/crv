# Costa Rica Vibes – Viator Tours (WordPress plugin)

Shows live Viator tours (photo, rating, duration, "from" price, free-cancellation badge) on any post or page with a shortcode. Every "Check availability" link carries your Viator partner tracking, so bookings earn commission.

## Install

1. Download `costa-rica-vibes-viator.zip` (or zip the `costa-rica-vibes-viator/` folder yourself).
2. WordPress admin → **Plugins → Add New → Upload Plugin** → choose the zip → **Activate**.
3. Get your API key from the Viator Partner dashboard (partners.viator.com, look under your account / API settings).
4. Add the key, either:
   - **Recommended:** in `wp-config.php`, above "That's all, stop editing":
     ```php
     define( 'CRV_VIATOR_API_KEY', 'your-key-here' );
     ```
   - or **Settings → Viator Tours → API key**.
5. On **Settings → Viator Tours**, click **Test connection**.
6. Use **Find a destination ID**. Leave the box blank and click Search to list every Costa Rica destination with its ID and a ready-to-copy shortcode.

## Shortcode

```text
[viator_tours destination="ID"]
[viator_tours destination="ID" search="zipline" count="3" sort="rating"]
[viator_tours destination="ID" flags="FREE_CANCELLATION" min_rating="4.5" max_price="150" title="Best tours in La Fortuna"]
```

| Attribute | What it does |
|---|---|
| `destination` | Viator destination ID (from the finder). |
| `search` | Keyword search, e.g. `rafting`, `turtle`, `airport transfer`. Works with or without `destination`. |
| `count` | Number of tours (default from settings). |
| `sort` | `featured` (default), `rating`, `price_low`, `price_high`, `newest`. |
| `flags` | Comma list: `FREE_CANCELLATION`, `PRIVATE_TOUR`, `SKIP_THE_LINE`, `SPECIAL_OFFER`, `LIKELY_TO_SELL_OUT`. |
| `tags` | Comma list of Viator tag IDs. |
| `min_rating` | e.g. `4.5`. |
| `max_price` | In your configured currency. |
| `columns` | 1–4 (default 3; collapses on tablets and phones). |
| `title` | Heading above the block. |
| `campaign` | Tracking label in Viator reports. Defaults to the page slug, so you can see which page earned each booking. |

In the block editor, add a **Shortcode** block and paste it in.

## Where to put it

- **Destination guides** (La Fortuna, Monteverde, Manuel Antonio, Tamarindo, Puerto Viejo): `[viator_tours destination="ID" sort="rating" title="Top-rated tours in …"]`.
- **Activity posts:** `search="zipline"`, `search="white water rafting"`, `search="turtle"` limited to the right destination, with `count="3"` directly under the section that talks about it.
- **Itineraries:** one `count="3"` block per day.
- **Arrival / "getting around" posts:** `search="airport transfer"` for SJO and Liberia.

## How it behaves

- Results are cached (6 hours by default), so pages stay fast and you stay well inside Viator's rate limits. **Clear tour cache** on the settings page forces a refresh.
- If Viator is down or rate-limits you, the last good results (up to 7 days old) keep showing.
- Errors (missing key, bad destination) are shown only to logged-in admins. Visitors see nothing.
- Links open in a new tab with `rel="sponsored"`, as Google requires for affiliate links.
- Colors: override `--crv-accent` on `.crv-tours` in your theme's Additional CSS to match your brand.

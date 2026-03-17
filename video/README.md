# Video App (`/video/`)

Moduł działa jako odrębna aplikacja URL, ale współdzieli:
- backend PHP z katalogu `/backend`
- sesję i połączenie DB z `/admin/db.php`
- globalne style/skrypty z `/assets`

## Strony
- `/video/index.php` - dashboard
- `/video/register.php` - rejestracja user
- `/video/login.php` - logowanie
- `/video/tokens.php` - zakup żetonów i historia zamówień
- `/video/my-videos.php` - dodawanie linku YouTube przez żeton
- `/video/trener.php` - lista filmów trenera, przejście do `/video.html`
- `/video/admin.php` - wejście do paneli admin

## Backend API
- `/backend/video_auth.php?action=register|login|logout|status`
- `/backend/video_tokens.php?action=list_types|create_order|my_balance|my_orders|list_trainers`
- `/backend/video_payment_p24.php?action=checkout|return|notify`
- `/backend/video.php?action=add_user_video_link`

## Konfiguracja ENV (Przelewy24)
- `APP_BASE_URL`
- `P24_SANDBOX` (`1`/`0`)
- `P24_MERCHANT_ID`
- `P24_POS_ID`
- `P24_API_KEY`
- `P24_CRC`

## DB migracja
Uruchom SQL:
- `admin/create_video_tokens_app.sql`


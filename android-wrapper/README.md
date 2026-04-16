# Android Wrapper

Base native Android pour sortir la messagerie des limites du Web Push navigateur.

## Ce que ce wrapper apporte

- canal dédié `messages_high_priority`
- importance haute
- vibration forte
- badge natif
- FCM côté Android natif
- WebView qui charge la messagerie existante

## Fichiers à fournir avant build

1. `app/google-services.json`
2. URL réelle de production dans `BuildConfig.BASE_URL`
3. endpoint backend de registration FCM dans `BuildConfig.REGISTER_TOKEN_URL`
4. service account Firebase côté serveur dans `messaging/runtime/firebase_service_account.json`
5. tu peux copier `messaging/runtime/firebase_service_account.example.json` comme base

## Build

1. Ouvrir `android-wrapper/` dans Android Studio.
2. Synchroniser Gradle.
3. Déposer `google-services.json` dans `android-wrapper/app/`.
4. Ajuster `BASE_URL` et `REGISTER_TOKEN_URL`.
5. Compiler l’APK.

## Étape backend encore nécessaire

Le backend PHP FCM natif est maintenant prévu :

- enregistrement token : `api/mobile/register_fcm_token.php`
- helper serveur : `messaging/fcm_lib.php`
- store tokens : `messaging/runtime/fcm_tokens.json`
- service account attendu : `messaging/runtime/firebase_service_account.json`
- template service account : `messaging/runtime/firebase_service_account.example.json`
- test CLI FCM : `php messaging/test_fcm_send.php <token>`
- test session utilisateur : `api({ajax:'send_test_fcm'}).then(console.log)`

Le Web Push VAPID navigateur reste aussi actif dans `messaging/`.

package cloud.coredesk.esperance.messaging

import android.Manifest
import android.annotation.SuppressLint
import android.content.Intent
import android.util.Log
import android.net.Uri
import android.content.pm.PackageManager
import android.os.SystemClock
import android.os.Build
import android.os.Bundle
import android.webkit.ConsoleMessage
import android.webkit.CookieManager
import android.webkit.GeolocationPermissions
import android.webkit.JavascriptInterface
import android.webkit.PermissionRequest
import android.webkit.ValueCallback
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebResourceResponse
import android.webkit.WebChromeClient
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.activity.ComponentActivity
import androidx.activity.result.contract.ActivityResultContracts
import androidx.core.content.ContextCompat
import cloud.coredesk.esperance.messaging.monitor.AppEventReporter
import cloud.coredesk.esperance.messaging.push.TokenSync
import com.google.firebase.messaging.FirebaseMessaging

class MainActivity : ComponentActivity() {
    companion object {
        private const val TAG = "MainActivity"
    }

    private lateinit var webView: WebView
    private var lastRecoveryTsMs: Long = 0L
    private var lastRecoveredUrl: String? = null

    // Callback WebView en attente de permission géolocalisation
    private var pendingGeoCallback: GeolocationPermissions.Callback? = null
    private var pendingGeoOrigin: String? = null

    // Callback WebView en attente de permission caméra/micro
    private var pendingWebPermRequest: PermissionRequest? = null
    private var fileChooserCallback: ValueCallback<Array<Uri>>? = null

    // ── Lanceurs de permissions ───────────────────────────────────────────

    private val multiplePermissionsLauncher = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) { _ ->
        // Ne valide la requête WebView que si Android a réellement accordé
        // les permissions demandées.
        val geoGranted = listOf(
            Manifest.permission.ACCESS_FINE_LOCATION,
            Manifest.permission.ACCESS_COARSE_LOCATION
        ).any { permission ->
            ContextCompat.checkSelfPermission(this, permission) == PackageManager.PERMISSION_GRANTED
        }
        pendingGeoCallback?.let { callback ->
            runOnUiThread {
                callback.invoke(pendingGeoOrigin, geoGranted, false)
            }
        }
        pendingGeoCallback = null
        pendingGeoOrigin = null

        pendingWebPermRequest?.let { request ->
            val grantedResources = request.resources.filter { resource ->
                when (resource) {
                    PermissionRequest.RESOURCE_VIDEO_CAPTURE ->
                        ContextCompat.checkSelfPermission(this, Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED
                    PermissionRequest.RESOURCE_AUDIO_CAPTURE ->
                        ContextCompat.checkSelfPermission(this, Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED
                    else -> true
                }
            }.toTypedArray()

            runOnUiThread {
                if (grantedResources.isNotEmpty()) {
                    request.grant(grantedResources)
                } else {
                    request.deny()
                }
            }
        }
        pendingWebPermRequest = null
    }

    private val fileChooserLauncher = registerForActivityResult(
        ActivityResultContracts.GetContent()
    ) { uri ->
        fileChooserCallback?.onReceiveValue(uri?.let { arrayOf(it) })
        fileChooserCallback = null
    }

    // ── Permissions à demander au démarrage ──────────────────────────────

    private fun buildRuntimePermissions(): Array<String> {
        val perms = mutableListOf(
            Manifest.permission.CAMERA,
            Manifest.permission.RECORD_AUDIO,
            Manifest.permission.ACCESS_FINE_LOCATION,
            Manifest.permission.ACCESS_COARSE_LOCATION,
            Manifest.permission.SEND_SMS,
            Manifest.permission.RECEIVE_SMS,
            Manifest.permission.READ_SMS
        )

        // Stockage : API < 33 → READ_EXTERNAL_STORAGE, API ≥ 33 → READ_MEDIA_*
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            perms += Manifest.permission.READ_MEDIA_IMAGES
            perms += Manifest.permission.READ_MEDIA_VIDEO
            perms += Manifest.permission.READ_MEDIA_AUDIO
            perms += Manifest.permission.POST_NOTIFICATIONS
        } else {
            perms += Manifest.permission.READ_EXTERNAL_STORAGE
            if (Build.VERSION.SDK_INT <= Build.VERSION_CODES.P) {
                perms += Manifest.permission.WRITE_EXTERNAL_STORAGE
            }
        }

        // Retourne uniquement celles qui ne sont pas encore accordées
        return perms.filter {
            ContextCompat.checkSelfPermission(this, it) != PackageManager.PERMISSION_GRANTED
        }.toTypedArray()
    }

    // ── onCreate ─────────────────────────────────────────────────────────

    @SuppressLint("SetJavaScriptEnabled")
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        webView = WebView(this)
        setContentView(webView)

        CookieManager.getInstance().setAcceptCookie(true)
        CookieManager.getInstance().setAcceptThirdPartyCookies(webView, true)
        webView.addJavascriptInterface(AndroidBridge(), "AndroidBridge")

        webView.settings.apply {
            javaScriptEnabled = true
            domStorageEnabled = true
            databaseEnabled = true
            mediaPlaybackRequiresUserGesture = false
            setGeolocationEnabled(true)
            cacheMode = WebSettings.LOAD_DEFAULT
            mixedContentMode = WebSettings.MIXED_CONTENT_COMPATIBILITY_MODE
            allowFileAccess = true
            allowContentAccess = true
            setSupportZoom(false)
            builtInZoomControls = false
        }

        webView.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(view: WebView?, request: WebResourceRequest?): Boolean {
                val target = request?.url?.toString()
                val normalized = normalizeInternalUrl(target)
                return if (normalized != null) {
                    if (normalized != target) {
                        view?.loadUrl(normalized)
                        true
                    } else {
                        false
                    }
                } else {
                    // Bloque les schémas non HTTP(S) (file://, intent://, etc.) dans la WebView.
                    true
                }
            }

            override fun onReceivedError(
                view: WebView?,
                request: WebResourceRequest?,
                error: WebResourceError?
            ) {
                super.onReceivedError(view, request, error)
                if (request?.isForMainFrame == true) {
                    val failingUrl = request.url?.toString() ?: webView.url
                    maybeRecoverFromCacheIssue(failingUrl, error?.description?.toString())
                    AppEventReporter.enqueue(
                        type = "webview_error",
                        message = error?.description?.toString() ?: "Erreur WebView",
                        url = failingUrl,
                        details = "code=${error?.errorCode ?: -1}"
                    )
                }
            }

            override fun onReceivedHttpError(
                view: WebView?,
                request: WebResourceRequest?,
                errorResponse: WebResourceResponse?
            ) {
                super.onReceivedHttpError(view, request, errorResponse)
                if (request?.isForMainFrame == true && (errorResponse?.statusCode ?: 200) >= 400) {
                    AppEventReporter.enqueue(
                        type = "webview_http_error",
                        message = "HTTP ${errorResponse?.statusCode ?: 0}",
                        url = request.url?.toString() ?: webView.url,
                        details = errorResponse?.reasonPhrase
                    )
                }
            }

            override fun onPageFinished(view: WebView?, url: String?) {
                super.onPageFinished(view, url)
                syncFirebaseToken(url)
                injectErrorHooks()
            }
        }

        webView.webChromeClient = object : WebChromeClient() {
            override fun onShowFileChooser(
                webView: WebView?,
                filePathCallback: ValueCallback<Array<Uri>>?,
                fileChooserParams: FileChooserParams?
            ): Boolean {
                fileChooserCallback?.onReceiveValue(null)
                fileChooserCallback = filePathCallback
                val acceptType = fileChooserParams
                    ?.acceptTypes
                    ?.firstOrNull { !it.isNullOrBlank() }
                    ?.ifBlank { "*/*" }
                    ?: "*/*"
                fileChooserLauncher.launch(acceptType)
                return true
            }

            override fun onConsoleMessage(consoleMessage: ConsoleMessage?): Boolean {
                if (consoleMessage?.messageLevel() == ConsoleMessage.MessageLevel.ERROR) {
                    AppEventReporter.enqueue(
                        type = "js_console_error",
                        message = consoleMessage.message(),
                        url = consoleMessage.sourceId() ?: webView.url,
                        details = "line=${consoleMessage.lineNumber()}"
                    )
                }
                return super.onConsoleMessage(consoleMessage)
            }

            // Permissions WebRTC (caméra, micro) depuis la WebView
            override fun onPermissionRequest(request: PermissionRequest) {
                Log.d(TAG, "Web permission request: ${request.resources.joinToString()}")
                val needAndroid = mutableListOf<String>()
                if (request.resources.contains(PermissionRequest.RESOURCE_VIDEO_CAPTURE)) {
                    if (ContextCompat.checkSelfPermission(
                            this@MainActivity, Manifest.permission.CAMERA
                        ) != PackageManager.PERMISSION_GRANTED
                    ) needAndroid += Manifest.permission.CAMERA
                }
                if (request.resources.contains(PermissionRequest.RESOURCE_AUDIO_CAPTURE)) {
                    if (ContextCompat.checkSelfPermission(
                            this@MainActivity, Manifest.permission.RECORD_AUDIO
                        ) != PackageManager.PERMISSION_GRANTED
                    ) needAndroid += Manifest.permission.RECORD_AUDIO
                }

                if (needAndroid.isEmpty()) {
                    runOnUiThread { request.grant(request.resources) }
                } else {
                    pendingWebPermRequest = request
                    multiplePermissionsLauncher.launch(needAndroid.toTypedArray())
                }
            }

            override fun onPermissionRequestCanceled(request: PermissionRequest?) {
                super.onPermissionRequestCanceled(request)
                Log.w(TAG, "Web permission request canceled")
                pendingWebPermRequest = null
            }

            // Géolocalisation depuis la WebView
            override fun onGeolocationPermissionsShowPrompt(
                origin: String,
                callback: GeolocationPermissions.Callback
            ) {
                val locationGranted = ContextCompat.checkSelfPermission(
                    this@MainActivity, Manifest.permission.ACCESS_FINE_LOCATION
                ) == PackageManager.PERMISSION_GRANTED

                if (locationGranted) {
                    runOnUiThread {
                        callback.invoke(origin, true, false)
                    }
                } else {
                    pendingGeoCallback = callback
                    pendingGeoOrigin = origin
                    multiplePermissionsLauncher.launch(
                        arrayOf(
                            Manifest.permission.ACCESS_FINE_LOCATION,
                            Manifest.permission.ACCESS_COARSE_LOCATION
                        )
                    )
                }
            }
        }

        if (savedInstanceState != null) {
            webView.restoreState(savedInstanceState)
        } else {
            webView.loadUrl(resolveTargetUrl(intent) ?: normalizeInternalUrl(BuildConfig.BASE_URL) ?: BuildConfig.BASE_URL)
        }

        syncFirebaseToken(webView.url)

        // Demander toutes les permissions manquantes au démarrage
        val missing = buildRuntimePermissions()
        if (missing.isNotEmpty()) {
            multiplePermissionsLauncher.launch(missing)
        }
    }

    override fun onSaveInstanceState(outState: Bundle) {
        webView.saveState(outState)
        super.onSaveInstanceState(outState)
    }

    override fun onResume() {
        super.onResume()
        syncFirebaseToken(webView.url)
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        resolveTargetUrl(intent)?.let { targetUrl ->
            if (targetUrl != webView.url) {
                webView.loadUrl(targetUrl)
            }
        }
    }

    override fun onBackPressed() {
        if (webView.canGoBack()) {
            webView.goBack()
            return
        }
        super.onBackPressed()
    }

    private fun syncFirebaseToken(cookieUrl: String? = null) {
        FirebaseMessaging.getInstance().token.addOnSuccessListener { token ->
            if (!token.isNullOrBlank()) {
                TokenSync.enqueue(token, cookieUrl)
            }
        }
    }

    private fun resolveTargetUrl(intent: Intent?): String? {
        val candidates = listOf(
            intent?.getStringExtra("target_url"),
            intent?.getStringExtra("url"),
            intent?.dataString
        )
        for (candidate in candidates) {
            if (candidate.isNullOrBlank()) continue
            val normalized = normalizeInternalUrl(candidate)
            if (!normalized.isNullOrBlank()) {
                return normalized
            }
        }
        return null
    }

    private fun normalizeInternalUrl(rawUrl: String?): String? {
        if (rawUrl.isNullOrBlank()) return null
        val candidate = Uri.decode(rawUrl.trim())
        val base = Uri.parse(BuildConfig.BASE_URL)
        val baseScheme = base.scheme ?: "https"
        val baseHost = base.host ?: return null

        // Chemins relatifs => URL absolue sur le domaine de base.
        if (candidate.startsWith("/")) {
            return "$baseScheme://$baseHost$candidate"
        }
        if (!candidate.contains("://")) {
            return "$baseScheme://$baseHost/${candidate.trimStart('/')}"
        }

        val uri = runCatching { Uri.parse(candidate) }.getOrNull() ?: return null
        val scheme = uri.scheme?.lowercase() ?: return null
        val host = uri.host?.lowercase() ?: return null
        if (scheme !in listOf("http", "https")) return null
        if (host != baseHost.lowercase()) return null
        return candidate
    }

    private fun maybeRecoverFromCacheIssue(failingUrl: String?, reason: String?) {
        val msg = (reason ?: "").lowercase()
        val shouldRecover = msg.contains("err_cache_miss") || msg.contains("err_file_not_found")
        if (!shouldRecover) return

        val now = SystemClock.elapsedRealtime()
        if (now - lastRecoveryTsMs < 3000) return

        val normalized = normalizeInternalUrl(failingUrl) ?: normalizeInternalUrl(BuildConfig.BASE_URL) ?: return
        if (lastRecoveredUrl == normalized && now - lastRecoveryTsMs < 15000) return

        lastRecoveryTsMs = now
        lastRecoveredUrl = normalized

        runOnUiThread {
            // Force un rafraîchissement réseau pour éviter un cache corrompu côté WebView.
            webView.settings.cacheMode = WebSettings.LOAD_NO_CACHE
            webView.loadUrl(normalized)
            webView.postDelayed({ webView.settings.cacheMode = WebSettings.LOAD_DEFAULT }, 2500)
        }
    }

    private fun injectErrorHooks() {
        val script = """
            (function(){
              if(window.__androidErrorHookInstalled){ return; }
              window.__androidErrorHookInstalled = true;
              window.addEventListener('error', function(e){
                try {
                  var details = [e.filename || '', e.lineno || 0, e.colno || 0].join(':');
                  AndroidBridge.reportAppIssue(String(e.message || 'JS error'), window.location.href, details);
                } catch(_e) {}
              });
              window.addEventListener('unhandledrejection', function(e){
                try {
                  var reason = e && e.reason ? String(e.reason) : 'Unhandled promise rejection';
                  AndroidBridge.reportAppIssue(reason, window.location.href, 'promise');
                } catch(_e) {}
              });
            })();
        """.trimIndent()
        webView.evaluateJavascript(script, null)
    }

    inner class AndroidBridge {
        @JavascriptInterface
        fun syncPushToken(currentUrl: String?) {
            runOnUiThread {
                syncFirebaseToken(currentUrl ?: webView.url)
            }
        }

        @JavascriptInterface
        fun reportAppIssue(message: String?, currentUrl: String?, details: String?) {
            AppEventReporter.enqueue(
                type = "js_runtime_error",
                message = message ?: "Erreur app",
                url = currentUrl ?: webView.url,
                details = details
            )
        }
    }
}

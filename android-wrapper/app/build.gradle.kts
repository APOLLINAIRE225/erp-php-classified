plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("com.google.gms.google-services")
}

android {
    namespace = "cloud.coredesk.esperance.messaging"
    compileSdk = 34

    defaultConfig {
        applicationId = "cloud.coredesk.esperance.messaging"
        minSdk = 26
        targetSdk = 34
        versionCode = 2
        versionName = "1.0.1"

        buildConfigField("String", "BASE_URL", "\"https://esperanceh20.com/messaging/messagerie.php\"")
        buildConfigField("String", "REGISTER_TOKEN_URL", "\"https://esperanceh20.com/api/mobile/register_fcm_token.php\"")
        buildConfigField("String", "REPORT_EVENT_URL", "\"https://esperanceh20.com/api/mobile/report_app_event.php\"")
        buildConfigField("String", "PUSH_CHANNEL_ID", "\"messages_high_priority\"")
        buildConfigField("String", "PUSH_CHANNEL_NAME", "\"Messages prioritaires\"")
    }

    signingConfigs {
        create("release") {
            storeFile = file("../signing/esperance-release.jks")
            storePassword = "esperance2024"
            keyAlias = "esperance"
            keyPassword = "esperance2024"
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            signingConfig = signingConfigs.getByName("release")
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro"
            )
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions {
        jvmTarget = "17"
    }
    buildFeatures {
        buildConfig = true
    }
}

dependencies {
    implementation("androidx.core:core-ktx:1.13.1")
    implementation("androidx.appcompat:appcompat:1.7.0")
    implementation("com.google.android.material:material:1.12.0")
    implementation("androidx.webkit:webkit:1.11.0")
    implementation("com.google.firebase:firebase-messaging-ktx:24.0.1")
    implementation("com.squareup.okhttp3:okhttp:4.12.0")
}

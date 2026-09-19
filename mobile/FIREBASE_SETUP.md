# Firebase Cloud Messaging (FCM HTTP v1) Setup Guide for Sahdev Mobile

This guide walks you through configuring **Google Firebase Cloud Messaging (FCM)** for Sahdev Mobile and your WHMCS server. 

With FCM HTTP v1 configured, staff mobile phones will **reliably receive live notifications** for:
- 🚨 **Urgent Live Chat Human Summons** (Full-screen ringing alert or chime)
- 💬 **Active Live Chat Customer Messages**
- 🎫 **WHMCS Support Tickets & Customer Replies**
- ⚠️ **Autonomous AI & System Fallback Notices**

Notifications wake devices even if the app is closed, swiped away from recent apps, or if the phone is locked in deep Android Doze mode.

---

## Step 1: Create a Google Firebase Project (Free)

1. Go to the [Firebase Console](https://console.firebase.google.com/) and sign in with your Google account.
2. Click **Add project** (or **Create a project**).
3. Enter a project name (e.g. `sahdev-support` or your company name) and click **Continue**.
4. (Optional) You can disable Google Analytics if you don't need analytics, then click **Create project**.
5. Once your project is ready, click **Continue**.

---

## Step 2: Register the Android App & Download `google-services.json`

1. On your Firebase Project Overview page, click the **Android** icon to add an Android app.
2. Enter the Android package name:
   ```
   com.sahdev.ai
   ```
   *(If you customized the application ID in `mobile/android/app/build.gradle`, enter your custom ID here).*
3. App nickname (optional): `Sahdev Mobile Support`
4. Click **Register app**.
5. Click **Download google-services.json**.
6. Place this downloaded file directly into:
   ```
   mobile/android/app/google-services.json
   ```
   *(Replace the placeholder file already in that folder).*
7. In the Firebase Console, you can skip the remaining SDK setup steps and click through to the console.

---

## Step 3: Generate the Firebase Service Account Key (for WHMCS)

WHMCS connects securely to Google's FCM HTTP v1 API using an OAuth 2.0 Service Account key:

1. In the Firebase Console, click the **Gear icon (Project Settings)** next to *Project Overview* in the top left.
2. Select the **Service accounts** tab.
3. Verify that **Firebase Admin SDK** is selected.
4. Click the blue **Generate new private key** button.
5. Click **Generate key** in the confirmation popup. A JSON file will download to your computer (e.g. `sahdev-support-firebase-adminsdk-xxxxx.json`).
6. Open this JSON file in any text editor (Notepad, VS Code, etc.) and copy its entire contents.

---

## Step 4: Configure WHMCS Sahdev Settings

1. Log into your **WHMCS Admin Area**.
2. Navigate to **Addons > Sahdev AI Intelligence > Settings**.
3. Click the **Mobile & Firebase Push** tab.
4. Check the box: **Enable Firebase Cloud Messaging Push Notifications**.
5. Paste the entire JSON you copied in Step 3 into the **Firebase Service Account Private Key JSON** field.
6. The *Firebase Project ID* will be auto-detected, or you can verify it matches your Firebase project ID.
7. Customize your dispatch triggers (Summons, Chat Messages, Tickets, System Alerts).
8. Click **Save All Settings**.

---

## Step 5: Test Push Notification

1. Open the Sahdev Support mobile app on your Android phone.
2. Pair the app with your WHMCS instance (via **Pair Mobile App** QR scan or credentials login).
3. Return to **WHMCS Admin > Addons > Sahdev > Settings > Mobile & Firebase Push**.
4. Notice that **Registered Staff Devices** now shows `1 device online`.
5. Click the **Send Test Push to Staff Devices** button!
6. Your Android phone will immediately receive a test notification:
   > 🔥 **Sahdev Push Test**  
   > Firebase Cloud Messaging HTTP v1 connection is active and working!

---

## In-App Staff Notification Customization

Staff members can customize their individual alert styles within the app:
1. Open Sahdev Mobile > tap the **Settings** gear icon.
2. Under **Push Notifications & Firebase (FCM)**:
   - Choose **Summon Alert Style**:
     - **Continuous Ringing Alarm**: Rings loudly like an incoming phone call until staff taps or opens the conversation (recommended so urgent visitors are never missed).
     - **Single Notification Chime**: Plays one pleasant notification sound without ongoing ringing.
   - Toggle specific notification channels on or off according to duty preferences.
   - Choose from **12 custom alert sound profiles**.

---

## Troubleshooting Checklist

| Issue | Resolution |
|---|---|
| **Android 13+ Notification Prompt** | On Android 13 or higher, ensure you tapped **Allow** when the app requested notification permissions on first launch. You can also verify in Android Settings > Apps > Sahdev Support > Notifications. |
| **"Invalid Firebase Service Account JSON" error in WHMCS** | Ensure you copied the entire JSON file from Step 3, including the outer `{ ... }`, `project_id`, `client_email`, and `private_key` fields. |
| **Push not received when screen locked** | Open Sahdev Mobile > Settings > tap **Open Battery Exemption Settings** > set battery usage to **Unrestricted**. This prevents aggressive manufacturer battery killers (Xiaomi MIUI, Samsung OneUI, Huawei) from suspending network packets. |
| **Google Cloud API not enabled** | In rare cases on new Google Cloud accounts, ensure the **Firebase Cloud Messaging API** is enabled in [Google Cloud Console API Library](https://console.cloud.google.com/apis/library/fcm.googleapis.com). |

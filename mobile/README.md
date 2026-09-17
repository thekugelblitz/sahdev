# Sahdev Mobile Live Support (Android App)

A dedicated, lightweight, high-performance mobile application for WHMCS staff to manage live website customer chats on the go, built with Flutter.

Modeled after enterprise live chat consoles like **Tawk.to**, Sahdev Mobile allows support agents to respond in real time whenever a visitor summons human support on your website or WHMCS client area.

---

## Key Features

1. **Urgent Human Summon Dispatch**:
   - Audio alerts (Configurable: Continuous Ringing Alarm or Single Chime) and vibration when someone clicks *"Talk to Human"*.
   - Heads-up Android notifications.
2. **Real-Time Live Queue**:
   - Filter tabs: **Summoned** (Urgent), **Active AI**, **Taken Over** (Human Staff), **My Chats**, and **Closed**.
   - Live unread badges and search filter by visitor name, email, or domain.
3. **Live Sneak-Peek Keystrokes**:
   - Watch what visitors type character-by-character in real time before they even press send.
4. **1-Tap Human Takeover & Handback**:
   - Instantly pause the autonomous AI Copilot to take personal control of a conversation, or hand it back to AI with 1 tap.
5. **AI Co-Pilot Reply Assist**:
   - 1-tap **"AI Suggest"** button generates a contextual reply draft using your active Sahdev AI engine (Gemini / OpenRouter / LM Studio) for staff to review and send.
6. **WHMCS Client Profile Drawer**:
   - View client's active hosting services, domains, next due dates, unpaid invoices, and open ticket count directly within the chat screen.
7. **Canned Responses / Macros**:
   - Quick search and 1-tap insertion for `/hi`, `/wait`, `/dns`, `/escalate`, and `/bye`.
8. **Instant QR Code Pairing**:
   - Scan the QR code generated in the WHMCS Admin Live Console (`Pair Mobile App` button) to connect in 3 seconds without typing passwords.
9. **Lightweight & Battery Friendly**:
   - Zero third-party push dependencies (no mandatory Google Firebase or OneSignal accounts).
   - Clean foreground/background polling service with an **Online / Offline** presence switch.

---

## Building the Android APK

### Option A: Automatic Cloud Build (Recommended — No Local SDK Required)

A GitHub Actions workflow is pre-configured at `.github/workflows/build_apk.yml`.

1. Commit and push the repository to GitHub:
   ```bash
   git add .
   git commit -m "Add Sahdev Mobile Live Support Flutter app"
   git push origin main
   ```
2. Go to your GitHub repository > **Actions** tab > select **Build Sahdev Android APK**.
3. Once completed (~3-4 minutes), click the run and download the **`sahdev-support-release-apk`** artifact.
4. Transfer the `app-release.apk` to any Android phone and install!

---

### Option B: Local Build with Flutter CLI

If you have Flutter installed on your computer:

```bash
cd mobile
flutter pub get
flutter build apk --release
```

The output APK will be generated at:
`mobile/build/app/outputs/flutter-apk/app-release.apk`

---

## How to Connect Staff Phones

### Method 1: Instant QR Code Scan (Fastest)
1. Log into your WHMCS Admin Area on your desktop/laptop.
2. Go to **Addons > Sahdev > Live Console**.
3. In the top navigation bar, click the blue **`Pair Mobile App`** button.
4. Open the Sahdev Support App on your Android phone and tap **`Scan QR Code to Pair`**.
5. Point your phone camera at the computer screen. You are instantly connected!

### Method 2: Manual WHMCS Login
1. Open the app on your phone.
2. Enter your WHMCS Base URL (e.g. `https://yourwhmcs.com`).
3. Enter your WHMCS Administrator Username and Password.
4. Tap **`Sign In as Staff`**.

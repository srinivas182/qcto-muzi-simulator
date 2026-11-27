# ADR 0003 — Mobile app (Android first, iPhone and tablet)

Status: accepted (Sprint 1.6) · Date: 2026-11-27

## One store app for every academy
Learners install **SimuWork** once, choose their language, then enter their academy code or scan the
academy's QR code. The app sends the academy as `X-Tenant` to the platform API host and shows that
academy's name, logo and colours from then on. Branded per-academy store apps (white-label builds) can be
produced later from the same code base with EAS build profiles; they are not needed to launch.

## Stack
Expo SDK 58 / React Native 0.88 (new architecture, Hermes), Expo Router (file-based screens),
TanStack Query with a persisted cache, `expo-secure-store` for the token, `expo-camera` for QR, NetInfo for
connectivity, `expo-haptics`. System fonts (no font download).

## Shared with web, by design only
`packages/core`: API types, translations (all four languages), brand defaults and contrast rules, menu
helpers, and the **locked footer wording**. Components, layout and navigation are mobile-native and separate
from web (bottom tabs, bottom sheets, large touch targets, haptics, pull-to-refresh).

## Sign-in and security
Phone code (SMS auto-fill on Android via `sms-otp`, iOS `oneTimeCode`) or email + password with two-factor.
The API issues a 60-day device token (`client=mobile`), stored in the Android Keystore / iOS Keychain
(`AFTER_FIRST_UNLOCK_THIS_DEVICE_ONLY`, never backed up). A 401 signs the device out locally.

## Offline and data cost
- Server data is cached on the device (7 days), so the app opens instantly and offline with the last state.
- An offline banner explains what still works.
- **Data saver is on by default:** large downloads (simulators, videos) wait for Wi-Fi. Used from Sprint 2.
- All languages ship inside the app (a few KB each) so switching works offline.

## Speed budget
- Hermes bytecode bundle ≤ 3.5 MB (currently ~2.9 MB, ~1.3 MB compressed in the download), checked in CI.
- Icons are imported one file each: Metro does not tree-shake, and the icon barrel alone added ~2.6 MB.
- Target app download < 25 MB and cold start < 2 s on a budget Android phone — measured with EAS builds
  once store builds start.

Since Sprint 2.4, CI also builds the release APK (no EAS: `expo prebuild` + Gradle). It fails if the arm64
APK reaches 25 MB, and it measures cold start on an emulator on every mobile PR (see `docs/sprints/sprint-02.md`).

## Locked footer
`<Screen>` renders the footer at the end of every screen's content (never under the tab bar); the full-screen
camera shows it over the preview. `test/footer.test.tsx` discovers every route file and opens each one.

## Roles on mobile (for now)
Learners get Home · Simulate · Courses · Progress · Me. Other roles get Home · Me with a pointer to the web
portal; facilitator tools (attendance with QR + selfie + GPS) arrive with the attendance module.

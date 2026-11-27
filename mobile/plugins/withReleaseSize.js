// Release-build settings for small downloads on budget Android phones (roadmap commitment C4).
// Applied by `expo prebuild`, so the generated android/ folder is never edited by hand.
const { withGradleProperties } = require('expo/config-plugins');

const SETTINGS = {
  // Remove unused Java/Kotlin code and resources.
  'android.enableMinifyInReleaseBuilds': 'true',
  'android.enableShrinkResourcesInReleaseBuilds': 'true',
  // Compress native libraries inside the APK: a smaller download matters more than install speed when
  // learners pay for data or receive the APK over WhatsApp.
  'expo.useLegacyPackaging': 'true',
  // The app shows no GIFs; leave the GIF decoder out.
  'expo.gif.enabled': 'false',
  'expo.webp.animated': 'false',
};

module.exports = function withReleaseSize(config) {
  return withGradleProperties(config, (cfg) => {
    for (const [key, value] of Object.entries(SETTINGS)) {
      const existing = cfg.modResults.find((p) => p.type === 'property' && p.key === key);
      if (existing) existing.value = value;
      else cfg.modResults.push({ type: 'property', key, value });
    }
    return cfg;
  });
};

const expoPreset = require('jest-expo/jest-preset');

/** @type {import('jest').Config} */
module.exports = {
  preset: 'jest-expo',
  setupFilesAfterEnv: ['<rootDir>/test/setup.ts'],
  moduleNameMapper: {
    '^@/(.*)$': '<rootDir>/src/$1',
    '^@test/(.*)$': '<rootDir>/test/$1',
    // jest-expo resolves the `react-native` export condition, which msw maps to null for `msw/node`.
    '^msw/node$': '<rootDir>/node_modules/msw/lib/node/index.js',
  },
  // msw's dependencies ship ESM-only `.mjs`: same Babel transform as the app code.
  transform: {
    '\\.mjs$': expoPreset.transform['\\.[jt]sx?$'],
  },
  transformIgnorePatterns: [
    'node_modules/(?!((jest-)?react-native|@react-native(-community)?)|expo(nent)?|@expo(nent)?/.*|@expo-google-fonts/.*|react-navigation|@react-navigation/.*|react-native-svg|rettime|until-async|@mswjs/.*|@open-draft/.*)',
  ],
  testPathIgnorePatterns: ['/node_modules/', '/.expo/'],
};

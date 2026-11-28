// @ts-check
const { defineConfig, devices } = require('@playwright/test');

module.exports = defineConfig({
	testDir: './specs',
	timeout: 180000,
	expect: { timeout: 30000 },
	fullyParallel: false,
	forbidOnly: !!process.env.CI,
	retries: 0,
	workers: 1,
	reporter: [['list']],
	use: {
		baseURL: process.env.IWORKS_BASE_URL || 'https://www.iworks.new',
		ignoreHTTPSErrors: true,
		trace: 'on-first-retry',
		viewport: { width: 1280, height: 800 },
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices['Desktop Chrome'] },
		},
	],
});

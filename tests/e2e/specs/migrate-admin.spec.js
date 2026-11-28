// @ts-check
const { test, expect } = require('@playwright/test');
const { createAuthCookies } = require('../helpers/wp-auth');

const RECEIVE_URL = '/wp-admin/admin.php?page=iworksie-migrate';
const GIVE_URL = '/wp-admin/admin.php?page=iworksie-migrate&tab=give';

test.describe('iWorks.ie Migrate admin', () => {
	/** @type {string[]} */
	const ourConsoleErrors = [];

	test.beforeEach(async ({ context, page }) => {
		ourConsoleErrors.length = 0;
		await context.addCookies(createAuthCookies());
		page.on('console', (msg) => {
			if (msg.type() !== 'error') return;
			const text = msg.text();
			if (/iwiemg|iworksie-migrate|iwt-core/i.test(text)) {
				ourConsoleErrors.push(text);
			}
		});
		page.on('pageerror', (err) => {
			const text = String(err);
			if (/iwiemg|iworksie-migrate|iwt-core/i.test(text)) {
				ourConsoleErrors.push(text);
			}
		});
	});

	test('receive tab loads core assets and screens', async ({ page }) => {
		await page.goto(RECEIVE_URL);
		await expect(page.locator('.iwt-page')).toBeVisible({ timeout: 30000 });
		await expect(page.locator('.iwt-page__header')).toBeVisible();
		await expect(page.locator('.iwt-notices')).toHaveCount(1);
		await expect(page.getByRole('heading', { name: /Migrate/i }).first()).toBeVisible();
		await expect(page.locator('#iwiemg-key')).toBeVisible();
		await expect(page.locator('[data-iwiemg-preflight]')).toBeVisible();
		await expect(page.locator('[data-iwiemg-step2]')).toHaveCount(1);

		await expect(page.locator('link[href*="iwt-core.css"]').first()).toHaveCount(1);
		await expect(page.locator('script[src*="iwt-core.js"]').first()).toHaveCount(1);

		const cssOk = await page.evaluate(async () => {
			const link = document.querySelector('link[href*="iwt-core.css"]');
			if (!link) return false;
			const res = await fetch(link.getAttribute('href'));
			return res.ok;
		});
		const jsOk = await page.evaluate(async () => {
			const script = document.querySelector('script[src*="iwt-core.js"]');
			if (!script) return false;
			const res = await fetch(script.getAttribute('src'));
			return res.ok;
		});
		expect(cssOk).toBeTruthy();
		expect(jsOk).toBeTruthy();
		expect(ourConsoleErrors, ourConsoleErrors.join(' | ')).toHaveLength(0);
	});

	test('give tab loads create/revoke UI and core assets', async ({ page }) => {
		await page.goto(GIVE_URL);
		await expect(page.locator('.iwt-page')).toBeVisible({ timeout: 30000 });
		await expect(page.locator('.iwt-page__header')).toBeVisible();
		await expect(page.getByRole('heading', { name: /Migration key/i })).toBeVisible();
		await expect(page.locator('[data-iwiemg-key-create]')).toBeVisible();
		await expect(page.locator('[data-iwiemg-key-revoke]')).toHaveCount(1);
		await expect(page.locator('link[href*="iwt-core.css"]').first()).toHaveCount(1);
		await expect(page.locator('script[src*="iwt-core.js"]').first()).toHaveCount(1);
		expect(ourConsoleErrors, ourConsoleErrors.join(' | ')).toHaveLength(0);
	});
});

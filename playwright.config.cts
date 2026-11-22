import {defineConfig, devices} from "@playwright/test";

// The DDEV router serves the instance and the mock IdP over plain HTTP (see Tests/Acceptance/Fixtures/additional.php)
const baseURL = process.env.PLAYWRIGHT_BASE_URL || `http://${process.env.VIRTUAL_HOST.split(',')[0]}`;
console.log(`Testing against: ${baseURL}`);

export default defineConfig({
    testDir: "./Tests/Playwright",
    outputDir: "./Tests/Playwright/test-results",
    timeout: 30 * 1000,
    forbidOnly: !!process.env.CI,
    fullyParallel: false,
    retries: process.env.CI ? 1 : 0,
    workers: 1,
    reporter: process.env.CI
        ? [["list"], ["html", { open: "never", outputFolder: "Tests/Playwright/playwright-report" }], ["junit", { outputFile: "Tests/Playwright/test-results/junit.xml" }]]
        : [["list"], ["html", { open: "never", outputFolder: "Tests/Playwright/playwright-report" }]],
    use: {
        baseURL,
        trace: "on-first-retry",
        screenshot: "only-on-failure",
    },

    projects: [
        {
            name: "chromium",
            use: {...devices["Desktop Chrome"]}
        }
    ]
});

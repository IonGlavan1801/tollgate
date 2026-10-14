import { test, expect } from "@playwright/test";

test("sandbox access is gated and provider credentials fail closed", async ({
    page,
}) => {
    test.skip(
        !process.env.TEST_SANDBOX_CODE,
        "Requires the sandbox gate on the test server",
    );
    await page.goto("/");
    await expect(page.getByTestId("launch-sandbox")).toBeVisible();
    await page.getByLabel("Sandbox access code").fill("incorrect-code");
    await page.getByTestId("launch-sandbox").click();
    await expect(page.getByRole("alert")).toHaveText(
        "Invalid sandbox access code.",
    );
    await expect(page.getByLabel("Sandbox access code")).toHaveValue("");
    await page
        .getByLabel("Sandbox access code")
        .fill(process.env.TEST_SANDBOX_CODE);
    await page.getByTestId("launch-sandbox").click();
    await expect(page.getByTestId("create-sandbox-order")).toBeVisible();
    await expect(page.locator(".mode")).toHaveText("SANDBOX");
    await expect(page.getByTestId("scenario-dispute")).toHaveCount(0);
    await expect(page.getByText("VIRTUAL CLOCK", { exact: false })).toHaveCount(
        0,
    );
    await page.screenshot({
        path: ".local/screenshots/sandbox-desktop.png",
        fullPage: true,
    });
    await page.setViewportSize({ width: 390, height: 660 });
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
    ).toBe(true);
    await page.screenshot({
        path: ".local/screenshots/sandbox-mobile.png",
        fullPage: true,
    });
    await page.getByTestId("create-sandbox-order").click();
    await expect(page.getByTestId("error")).toHaveText(
        "Sandbox is disabled or test credentials are missing.",
    );
    await page.getByTestId("reset").click();
    await expect(page.locator(".mode")).toHaveText("SIMULATED");
    await expect(page.getByTestId("launch")).toBeVisible();
});

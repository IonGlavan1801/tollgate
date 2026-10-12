import { test, expect } from "@playwright/test";

async function launch(page) {
    await page.goto("/");
    await page.getByTestId("launch").click();
    await expect(
        page.getByText("Your isolated demo session is ready."),
    ).toBeVisible();
}

test("checkout, duplicate containment, refund replay and plan confirmation", async ({
    page,
}) => {
    const errors = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await launch(page);
    await page.getByTestId("scenario-duplicates").click();
    await expect(page.getByTestId("order-status")).toHaveText("paid");
    await expect(page.getByTestId("subscription-status")).toHaveText(
        "subscription: active",
    );
    await expect(page.getByTestId("delivery-count")).toHaveText("4");
    await page.getByTestId("refund").click();
    await expect(
        page.getByText(
            "50% refund recorded. Replaying this request has no extra effect.",
        ),
    ).toBeVisible();
    await page.getByTestId("refund").click();
    await expect(
        page.getByText(
            "50% refund recorded. Replaying this request has no extra effect.",
        ),
    ).toBeVisible();
    await expect(page.getByText("€9.50", { exact: true })).toHaveCount(2);
    await page.getByRole("button", { name: "+15 days", exact: true }).click();
    await expect(
        page.getByText("Virtual clock advanced by 360 hours."),
    ).toBeVisible();
    await page.getByTestId("preview").click();
    await expect(
        page.getByText("€40.00 charge", { exact: false }),
    ).toBeVisible();
    await page.getByTestId("confirm-change").click();
    await expect(
        page.getByText(
            "Plan changed. The proration is recorded in the ledger.",
        ),
    ).toBeVisible();
    await expect(page.getByTestId("error")).toHaveCount(0);
    expect(errors).toEqual([]);
    await page
        .getByRole("heading", { name: "Payment flow", exact: true })
        .click();
    await page.screenshot({
        path: ".local/screenshots/desktop.png",
        fullPage: true,
    });
});

test("failed payment retries, suspension and recovery", async ({ page }) => {
    await launch(page);
    await page.getByTestId("scenario-dunning").click();
    await expect(page.getByTestId("subscription-status")).toHaveText(
        "subscription: past due",
    );
    for (const [name, hours] of [
        ["+1 hour", 1],
        ["+23 hours", 23],
        ["+48 hours", 48],
    ]) {
        await page.getByRole("button", { name, exact: true }).click();
        await expect(
            page.getByText(`Virtual clock advanced by ${hours} hours.`),
        ).toBeVisible();
    }
    await expect(page.getByTestId("subscription-status")).toHaveText(
        "subscription: suspended",
    );
    await page.getByTestId("recover").click();
    await expect(page.getByTestId("subscription-status")).toHaveText(
        "subscription: active",
    );
});

test("SCA remains pending until confirmation; reset isolates the session", async ({
    page,
}) => {
    await launch(page);
    await page.getByTestId("scenario-sca").click();
    await expect(page.getByTestId("order-status")).toHaveText(
        "requires action",
    );
    await expect(page.getByTestId("subscription-status")).toHaveText(
        "subscription: trialing",
    );
    await page.getByTestId("recover").click();
    await expect(page.getByTestId("order-status")).toHaveText("paid");
    await page.getByTestId("reset").click();
    await expect(page.getByTestId("delivery-count")).toHaveText("0");
    await expect(page.getByText("Choose a scenario to begin.")).toBeVisible();
});

test("mobile layout, provider boundaries and tax review", async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 660 });
    await launch(page);
    await page.getByLabel("Provider for new orders").selectOption("mollie");
    await expect(page.getByTestId("scenario-sca")).toBeDisabled();
    await expect(page.getByTestId("scenario-proration")).toBeDisabled();
    await page.getByTestId("scenario-tax").click();
    await expect(
        page.getByText("VIES fixture unavailable.", { exact: false }),
    ).toBeVisible();
    await expect(page.getByTestId("order-status")).toHaveText("paid");
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
    ).toBe(true);
    await page.screenshot({
        path: ".local/screenshots/mobile.png",
        fullPage: true,
    });
});

test("out-of-order delivery and worker retry converge", async ({ page }) => {
    await launch(page);
    await page.getByTestId("scenario-out_of_order").click();
    await expect(page.getByTestId("order-status")).toHaveText("paid");
    await expect(page.getByTestId("timeline")).toContainText("deferred");
    await page.getByTestId("scenario-worker_crash").click();
    await expect(page.getByTestId("order-status")).toHaveText("paid");
    await expect(page.getByTestId("error")).toHaveCount(0);
    await expect(page.getByTestId("timeline")).toContainText(
        "worker.recovered",
    );
});

test("browser mutations require CSRF and reject a foreign origin", async ({
    request,
}) => {
    const response = await request.post("/livewire/update", {
        data: { components: [] },
        headers: { Origin: "https://untrusted.invalid" },
    });
    expect(response.status()).toBe(419);
});

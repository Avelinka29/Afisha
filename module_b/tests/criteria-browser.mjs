import { createRequire } from "node:module";
import fs from "node:fs";
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_PATH || "playwright");
const browser = await chromium.launch({
  headless: true,
  ...(process.env.CHROME_PATH
    ? { executablePath: process.env.CHROME_PATH }
    : { channel: "chrome" }),
});
const context = await browser.newContext({
  viewport: { width: 1440, height: 1000 },
});
const page = await context.newPage();
const errors = [];
page.on("pageerror", (error) => errors.push(error.message));
let checks = 0;
function check(value, label) {
  if (!value) throw new Error(label);
  checks++;
}
try {
  await page.goto("http://localhost:5173/");
  await page.locator(".event-card").first().waitFor();
  check(
    (await page.locator(".pagination").count()) === 1,
    "Пагинация видна на конкурсном наборе",
  );
  for (const q of [
    "о",
    "от",
    "отк",
    "откр",
    "откры",
    "открыт",
    "Участие бесплатное",
    "' OR 1=1 --",
  ]) {
    const response = page.waitForResponse(
      (r) =>
        r.url().startsWith("http://localhost:8000/api/events?") &&
        new URL(r.url()).searchParams.get("q") === q &&
        r.status() === 200,
    );
    await page.locator("#event-search").fill(q);
    const data = await (await response).json();
    await page.waitForFunction(
      (total) =>
        document.querySelector("#search-status")?.textContent ===
          `Найдено: ${total}. Поиск по названию и описанию.` &&
        !document
          .querySelector("#event-list-results")
          .hasAttribute("aria-busy"),
      data.meta.total,
    );
    check(
      await page
        .locator("#event-search")
        .evaluate((el) => el === document.activeElement),
      "Фокус остаётся в поиске",
    );
    for (const e of data.data)
      check(
        (e.title + " " + e.description).toLowerCase().includes(q.toLowerCase()),
        "Каждый результат содержит запрос",
      );
    if (q === "открыт")
      check(
        (await page.locator(".event-card mark").count()) > 0,
        "Совпадение подсвечено",
      );
    if (q === "Участие бесплатное")
      check(
        (await page.locator(".search-excerpt").count()) > 0,
        "Объяснено совпадение в описании",
      );
  }
  await page.locator("#event-search").fill("открыт");
  await page.locator("#event-search").fill("науч");
  await page.waitForFunction(
    () =>
      new URL(location.href).searchParams.get("q") === "науч" &&
      document
        .querySelector("#search-status")
        .textContent.startsWith("Найдено:"),
  );
  check(
    (await page.locator(".event-card").count()) === 1,
    "Старый ответ не подменяет новый запрос",
  );
  for (const width of [1920, 1440, 1024, 900, 768, 600, 390, 320]) {
    await page.setViewportSize({ width, height: 1000 });
    await page.goto("http://localhost:5173/");
    await page.locator(".event-card").first().waitFor();
    check(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
      "Адаптивность " + width,
    );
  }
  await page.route("http://localhost:8000/uploads/**", (route) =>
    route.abort(),
  );
  await page.goto("http://localhost:5173/");
  await page.locator(".event-card").first().waitFor();
  check(
    await page
      .locator(".event-cover")
      .first()
      .evaluate((el) => el.getBoundingClientRect().height > 100),
    "Без изображений обложка сохраняет место",
  );
  check(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
    "Без изображений нет горизонтального выхода",
  );
  check(errors.length === 0, errors.join("; "));
  const result = {
    checks,
    errors,
    widths: [1920, 1440, 1024, 900, 768, 600, 390, 320],
    liveSearch: true,
    imagesDisabled: true,
  };
  fs.writeFileSync(
    "module_a/storage/criteria-browser-report.json",
    JSON.stringify(result, null, 2),
  );
  console.log(JSON.stringify(result));
} finally {
  await browser.close();
}

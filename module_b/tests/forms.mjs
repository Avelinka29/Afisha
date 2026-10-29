import { createRequire } from "node:module";
import { readFileSync, writeFileSync, existsSync } from "node:fs";
import { fileURLToPath } from "node:url";
import path from "node:path";
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_PATH || "playwright");
const root = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  "../..",
);
const stateFile = path.join(root, "module_a/storage/smoke-state.json");
if (existsSync(stateFile))
  throw new Error("Сначала очистите предыдущую проверку");
const browser = await chromium.launch({
  headless: true,
  ...(process.env.CHROME_PATH
    ? { executablePath: process.env.CHROME_PATH }
    : { channel: "chrome" }),
});
const page = await browser.newPage({
  viewport: { width: 390, height: 844 },
  timezoneId: "Europe/Minsk",
});
page.setDefaultTimeout(10000);
const testApi = process.env.TEST_API_URL || "http://localhost:8000/api";
const health = await (await page.request.get(testApi + "/health")).json();
if (health.mail_transport !== "log") {
  await browser.close();
  throw new Error(
    "Для тестов форм нужен отдельный API с MAIL_TRANSPORT=log и TEST_API_URL",
  );
}
if (testApi !== "http://localhost:8000/api") {
  await page.route("http://localhost:8000/api/**", (route) =>
    route.continue({
      url: route.request().url().replace("http://localhost:8000/api", testApi),
    }),
  );
}
const errors = [];
page.on("pageerror", (error) => errors.push(error.message));
let checks = 0;
const assert = (value, message) => {
  if (!value) throw new Error(message);
  checks++;
};
const ready = async () => page.locator(".loading").waitFor({ state: "hidden" });
const goto = async (url) => {
  await page.goto("http://localhost:5173" + url);
  await ready();
};
const logout = async () => {
  await page.locator(".user-menu summary").click();
  await page.locator(".user-menu [data-logout]").click();
  await page.locator("dialog button[type=submit]").click();
  await page.waitForURL("http://localhost:5173/");
  await ready();
};
const mail = (email) =>
  readFileSync(path.join(root, "module_a/storage/mail.log"), "utf8")
    .trim()
    .split("\n")
    .map(JSON.parse)
    .reverse()
    .find((row) => row.to === email);
const email = "test-" + Date.now().toString(16) + "@example.com";
try {
  await goto("/register");
  await page.locator("[name=name]").fill("Тест форм");
  await page.locator("[name=email]").fill(email);
  await page.locator("[name=faculty]").fill("Информационные технологии");
  await page.locator("[name=course]").selectOption("2");
  await page.locator("[name=password]").fill("ProfSkills2026!");
  await page.locator("button[type=submit]").click();
  await page.getByRole("heading", { name: "Проверь свою почту" }).waitFor();
  checks++;
  writeFileSync(stateFile, JSON.stringify({ events: [], emails: [email] }));
  const verification = mail(email).text;
  await page.goto(verification);
  await page.waitForURL("http://localhost:5173/");
  await ready();
  assert(
    (await page.locator(".user-menu").count()) === 1,
    "Автовход после подтверждения",
  );
  await goto("/me");
  assert(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
    "Профиль на телефоне",
  );
  await page
    .locator("#password-form [name=current_password]")
    .fill("ProfSkills2026!");
  await page.locator("#password-form [name=password]").fill("Changed2026!");
  await page
    .locator("#password-form [name=password_confirmation]")
    .fill("Different2026!");
  await page.locator("#password-form button[type=submit]").click();
  await page.locator("#password-form .field-error").waitFor();
  assert(
    (await page
      .locator("#password-form [name=password_confirmation]")
      .getAttribute("aria-invalid")) === "true",
    "Несовпадение паролей",
  );
  await page
    .locator("#password-form [name=password_confirmation]")
    .fill("Changed2026!");
  await page.locator("#password-form button[type=submit]").click();
  await page.locator(".toast").filter({ hasText: "Пароль изменён" }).waitFor();
  checks++;
  await logout();
  await goto("/password-reset");
  await page.locator("[name=email]").fill(email);
  await page.locator("button[type=submit]").click();
  await page
    .locator(".toast")
    .filter({ hasText: "письмо восстановления" })
    .waitFor();
  checks++;
  const link = mail(email).text;
  await page.goto(link);
  await ready();
  await page.locator("[name=password]").fill("ProfSkills2026!");
  await page.locator("[name=password_confirmation]").fill("ProfSkills2026!");
  await page.locator("button[type=submit]").click();
  await page.waitForURL("**/login");
  await ready();
  checks++;
  await page.locator("[name=email]").fill(email);
  await page.locator("[name=password]").fill("ProfSkills2026!");
  await page.locator("button[type=submit]").click();
  await page.waitForURL("http://localhost:5173/");
  await ready();
  checks++;
  await logout();
  await goto("/login");
  await page.locator("[name=email]").fill("blocked@example.com");
  await page.locator("[name=password]").fill("ProfSkills2026!");
  await page.locator("button[type=submit]").click();
  await page.locator("[data-form-error]:visible").waitFor();
  assert(
    (await page.locator("[data-form-error]").innerText()).includes(
      "заблокирован",
    ),
    "Сообщение о блокировке",
  );
  await goto("/login");
  await page.locator("[name=email]").fill("rep1@example.com");
  await page.locator("[name=password]").fill("ProfSkills2026!");
  await page.locator("button[type=submit]").click();
  await page.waitForURL("http://localhost:5173/");
  await ready();
  await goto("/me/events");
  assert(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
    "Управление на телефоне",
  );
  await goto("/me/events/new");
  assert(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
    "Форма события на телефоне",
  );
  await page.locator("[name=club_id]").selectOption("1");
  await page.locator("[name=category_id]").selectOption("1");
  await page.locator("[name=title]").fill("Проверка валидации");
  await page.locator("textarea[name=description]").fill("Проверка дат");
  await page.locator("[name=starts_at]").fill("2027-01-01T12:00");
  await page.locator("[name=ends_at]").fill("2027-01-01T11:00");
  await page.locator("[name=location]").fill("МРК");
  await page.locator("button[value=save]").click();
  await page.locator("[name=ends_at][aria-invalid=true]").waitFor();
  checks++;
  await goto("/me/events/1/participants");
  assert(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
    "Таблица прокручивается внутри контейнера",
  );
  await page.route("http://localhost:8000/api/events**", (route) =>
    route.abort(),
  );
  await goto("/");
  assert(
    (await page.locator(".notice-error").innerText()).includes("Нет связи"),
    "Ошибка сети",
  );
  await page.unroute("http://localhost:8000/api/events**");
  await page.locator("[data-retry]").click();
  await page.locator(".event-card").first().waitFor();
  checks++;
  assert(errors.length === 0, errors.join("; "));
  console.log(JSON.stringify({ checks, errors }));
} finally {
  await browser.close();
}

import { createRequire } from "node:module";
import { writeFileSync, mkdirSync, readFileSync, existsSync } from "node:fs";
import { fileURLToPath } from "node:url";
import path from "node:path";
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_PATH || "playwright");
const root = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  "../..",
);
const browser = await chromium.launch({
  headless: true,
  ...(process.env.CHROME_PATH
    ? { executablePath: process.env.CHROME_PATH }
    : { channel: "chrome" }),
});
const context = await browser.newContext({
  viewport: { width: 1440, height: 1000 },
  timezoneId: "Europe/Minsk",
});
const page = await context.newPage();
page.setDefaultTimeout(12000);
const errors = [];
page.on("pageerror", (error) => errors.push(error.message));
const failures = [];
page.on("response", (response) => {
  if (response.url().includes("/src/") && response.status() >= 400)
    failures.push(response.url());
});
let checks = 0;
const assert = (value, message) => {
  if (!value) throw new Error(message);
  checks++;
};
const ready = async () => {
  await page.locator(".loading").waitFor({ state: "hidden" });
};
const goto = async (url) => {
  await page.goto("http://localhost:5173" + url);
  await ready();
};
const shot = async (name) => {
  mkdirSync(path.join(root, "tmp/browser"), { recursive: true });
  await page.screenshot({
    path: path.join(root, "tmp/browser", name + ".png"),
    fullPage: true,
  });
};
const login = async (email) => {
  await goto("/login");
  await page.locator("[name=email]").fill(email);
  await page.locator("[name=password]").fill("ProfSkills2026!");
  await page.locator("#login-form button[type=submit]").click();
  await page.waitForURL("http://localhost:5173/");
  await ready();
};
const logout = async () => {
  await page.locator(".user-menu summary").click();
  await page.locator(".user-menu [data-logout]").click();
  await page.locator("dialog button[type=submit]").click();
  await page.waitForURL("http://localhost:5173/");
  await ready();
};
const stateFile = path.join(root, "module_a/storage/smoke-state.json");
assert(!existsSync(stateFile), "Сначала очистите данные предыдущих тестов");
const fixtures = { events: [], emails: [] };
const save = () => writeFileSync(stateFile, JSON.stringify(fixtures));
try {
  await goto("/");
  assert(
    (await page.locator(".event-card").count()) === 6,
    "Первая страница содержит 6 событий",
  );
  await shot("desktop-home");
  await page.locator('[data-page="2"]').click();
  await ready();
  assert(
    new URL(page.url()).searchParams.get("page") === "2",
    "Переход на вторую страницу",
  );
  await page.getByRole("button", { name: "Наука", exact: true }).click();
  await ready();
  assert((await page.locator(".event-card").count()) === 2, "Фильтр категории");
  await page.locator("#event-search").fill("искусственный");
  await page.locator("#search-form button").click();
  await page.waitForFunction(()=>document.querySelectorAll(".event-card").length===1 && !document.querySelector("#event-list-results").hasAttribute("aria-busy"));
  assert((await page.locator(".event-card").count()) === 1, "Поиск");
  await page.locator("[name=club_id]").selectOption("1");
  await page.locator("#filters-form button").click();
  await ready();
  assert(
    (await page.locator(".empty-state").count()) === 1,
    "Пустой результат",
  );
  await goto("/events/1");
  await page.locator("#register-button").click();
  await page.waitForURL(/\/login/);
  await ready();
  await page.locator("[name=email]").fill("student@example.com");
  await page.locator("[name=password]").fill("incorrectpass");
  await page.locator("#login-form button[type=submit]").click();
  await page.locator("[data-form-error]:visible").waitFor();
  assert(
    (await page.locator("[data-form-error]").innerText()) !== "",
    "Ошибка входа",
  );
  await page.locator("[name=password]").fill("ProfSkills2026!");
  await page.locator("#login-form button[type=submit]").click();
  await page.waitForURL("**/events/1");
  await ready();
  assert(
    (await page.locator('.user-menu a[href="/me/events"]').count()) === 0,
    "Студенту скрыто управление",
  );
  await page.locator("#register-button").click();
  await page.locator(".registered-banner").waitFor();
  assert(
    await page
      .locator("#register-button")
      .innerText()
      .then((x) => x.includes("Отменить")),
    "Запись через интерфейс",
  );
  await page.locator("#like-button").click();
  await page.locator("#like-button[aria-pressed=true]").waitFor();
  await page.locator("#interested-button").click();
  await page.locator("#interested-button[aria-pressed=true]").waitFor();
  checks += 2;
  await goto("/me/registrations");
  assert(
    (await page.locator(".registration-item").count()) >= 1,
    "Список регистраций",
  );
  await page.locator('[data-unregister="1"]').click();
  await page.locator("dialog button[type=submit]").click();
  await page.locator('[data-unregister="1"]').waitFor({ state: "hidden" });
  checks++;
  await goto("/me/reactions");
  assert(
    (await page.locator('[data-remove-reaction="1"]').count()) === 2,
    "Обе поставленные реакции сохранены в кабинете",
  );
  for (let i = 0; i < 2; i++) {
    await page.locator('[data-remove-reaction="1"]').first().click();
    await page.locator(".loading").waitFor({ state: "hidden" });
    await page.waitForFunction(
      (n) =>
        document.querySelectorAll('[data-remove-reaction="1"]').length === n,
      1 - i,
    );
  }
  await goto("/me");
  await page.locator("#profile-form [name=name]").fill("Студент для проверки");
  await page.locator("#profile-form button[type=submit]").click();
  await page
    .locator(".toast")
    .filter({ hasText: "Профиль сохранён" })
    .waitFor();
  await ready();
  checks++;
  await goto("/me/notifications");
  assert((await page.locator(".notification-item").count()) > 0, "Уведомления");
  for (const [id, label] of [
    [3, "Все места заняты"],
    [4, "Мероприятие завершено"],
    [12, "Мероприятие отменено"],
  ]) {
    await goto("/events/" + id);
    assert(await page.locator("#register-button").isDisabled(), label);
  }
  await goto("/me/events");
  assert(
    await page
      .locator(".notice-error")
      .innerText()
      .then((x) => x.includes("прав")),
    "Прямой вход студента в управление запрещён",
  );
  await logout();
  await login("rep1@example.com");
  await goto("/me/events/new");
  await page.locator("[name=club_id]").selectOption("1");
  await page.locator("[name=category_id]").selectOption("1");
  const title = "Проверка интерфейса " + Date.now();
  await page.locator("[name=title]").fill(title);
  await page
    .locator("textarea[name=description]")
    .fill("Проверяем создание и публикацию через интерфейс.");
  const start = new Date(Date.now() + 86400000 * 5),
    end = new Date(start.getTime() + 7200000);
  const local = (d) =>
    new Date(d.getTime() + 3 * 3600000).toISOString().slice(0, 16);
  await page.locator("[name=starts_at]").fill(local(start));
  await page.locator("[name=ends_at]").fill(local(end));
  await page.locator("[name=location]").fill("Аудитория МРК");
  await page.locator("[name=capacity]").fill("20");
  await page.locator("button[value=save]").click();
  await page.waitForURL("**/me/events");
  await ready();
  const result = await context.request.get(
    "http://localhost:8000/api/me/events?per_page=100",
  );
  const created = (await result.json()).data.find(
    (event) => event.title === title,
  );
  assert(created?.status === "draft", "Черновик создан");
  fixtures.events.push({ id: created.id, title });
  save();
  await goto("/me/events/" + created.id + "/edit");
  assert(
    await page.locator("[name=club_id]").isDisabled(),
    "Комьюнити при редактировании фиксировано",
  );
  await page.locator("button[value=publish]").click();
  await page.waitForURL("**/me/events");
  await ready();
  await goto("/events/" + created.id);
  assert(
    await page.locator("#register-button").isEnabled(),
    "Событие опубликовано",
  );
  await goto("/me/events/" + created.id + "/participants");
  assert(
    (await page.locator(".empty-state").count()) === 1,
    "Пустой список участников",
  );
  const downloadPromise = page.waitForEvent("download");
  await page.locator("#export-button").click();
  const download = await downloadPromise;
  assert(download.suggestedFilename().endsWith(".txt"), "Экспорт участников");
  await goto("/me/events/" + created.id + "/edit");
  await page.locator("[data-event-action=cancel]").click();
  await page
    .locator("dialog textarea")
    .fill("Автоматическая проверка завершена");
  await page.locator("dialog button[type=submit]").click();
  await page.locator(".status-cancelled").waitFor();
  checks++;
  await goto("/me/events/10/edit");
  assert(
    (await page.locator(".notice-error").count()) === 1,
    "Чужой черновик скрыт",
  );
  await goto("/me/events");
  await shot("desktop-manage");
  await logout();
  await login("admin@example.com");
  await goto("/admin");
  assert(
    (await page.locator("#verification-form").count()) === 1,
    "Панель верификации",
  );
  await goto("/admin?tab=clubs");
  assert(
    (await page.locator(".directory-list h2").count()) === 4,
    "Управление Комьюнити",
  );
  await goto("/admin?tab=categories");
  assert(
    (await page.locator(".directory-list h2").count()) === 6,
    "Управление категориями",
  );
  await goto("/admin?tab=moderation");
  assert((await page.locator(".management-item").count()) === 8, "Модерация");
  await logout();
  for (const width of [1440, 768, 390, 320]) {
    await page.setViewportSize({ width, height: 900 });
    await goto("/");
    assert(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
      "Нет горизонтальной прокрутки: " + width,
    );
    if (width === 390) await shot("mobile-home");
    await goto("/events/1");
    assert(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
      "Карточка помещается: " + width,
    );
    if (width === 390) await shot("mobile-detail");
    await goto("/register");
    assert(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
      "Регистрация помещается: " + width,
    );
  }
  await page.setViewportSize({ width: 1440, height: 1000 });
  await goto("/login");
  await shot("desktop-login");
  assert(errors.length === 0, "Ошибки JavaScript: " + errors.join("; "));
  assert(failures.length === 0, "Все модули загружены");
  console.log(
    JSON.stringify(
      {
        checks,
        errors,
        screenshots: "tmp/browser",
        cleanup: "php module_a/tests/cleanup.php",
      },
      null,
      2,
    ),
  );
} catch (error) {
  await shot("failure");
  console.error("URL:", page.url());
  console.error("JS errors:", errors);
  throw error;
} finally {
  await browser.close();
}

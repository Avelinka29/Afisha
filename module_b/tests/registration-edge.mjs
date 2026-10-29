import { createRequire } from "node:module";
import { writeFileSync, existsSync } from "node:fs";
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
  throw new Error("Сначала очистите предыдущие проверки");
const browser = await chromium.launch({
  headless: true,
  ...(process.env.CHROME_PATH
    ? { executablePath: process.env.CHROME_PATH }
    : { channel: "chrome" }),
});
const context = await browser.newContext();
const api = "http://localhost:8000/api";
const client = context.request;
try {
  let response = await client.post(api + "/auth/login", {
    data: { email: "rep1@example.com", password: "ProfSkills2026!" },
  });
  if (response.status() !== 200) throw new Error("Вход представителя");
  const title = "Проверка перехода времени " + Date.now();
  response = await client.post(api + "/events", {
    data: {
      club_id: 1,
      category_id: 1,
      title,
      description: "Тест времени начала",
      starts_at: new Date(Date.now() + 9000).toISOString(),
      ends_at: new Date(Date.now() + 3600000).toISOString(),
      location: "МРК",
      format: "offline",
      capacity: 5,
    },
  });
  const event = (await response.json()).data;
  if (response.status() !== 201) throw new Error("Создание события");
  writeFileSync(
    stateFile,
    JSON.stringify({ events: [{ id: event.id, title }], emails: [] }),
  );
  await client.post(api + `/events/${event.id}/publish`, { data: {} });
  await client.post(api + "/auth/logout", { data: {} });
  await client.post(api + "/auth/login", {
    data: { email: "student@example.com", password: "ProfSkills2026!" },
  });
  response = await client.post(api + `/events/${event.id}/register`, {
    data: {},
  });
  if (response.status() !== 201) throw new Error("Запись до начала");
  const page = await context.newPage();
  await page.goto("http://localhost:5173/events/" + event.id);
  await page.locator(".registered-banner").waitFor();
  if (await page.locator("#register-button").isDisabled())
    throw new Error("До начала отмена должна быть доступна");
  await page.waitForFunction(
    () => document.querySelector("#register-button")?.disabled,
    {},
    { timeout: 14000 },
  );
  if (
    !(await page.locator("#register-button").innerText()).includes("началось")
  )
    throw new Error("Не показана причина блокировки");
  for (const method of ["POST", "DELETE"]) {
    response = await client.fetch(api + `/events/${event.id}/register`, {
      method,
      data: {},
    });
    const error = (await response.json()).error;
    if (response.status() !== 409 || error.code !== "EVENT_STARTED")
      throw new Error("Сервер не запретил действие после начала");
  }
  console.log(
    "Начало события на открытой странице: кнопка автоматически заблокирована, POST и DELETE вернули 409 EVENT_STARTED",
  );
} finally {
  await browser.close();
}

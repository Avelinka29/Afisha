import { fileUrl } from "./api.js";
import { state, isRepresentative, isAdmin } from "./state.js";
// Пользовательский текст выводим как текст, чтобы он не превратился в HTML.
export const esc = (value) =>
  String(value ?? "").replace(
    /[&<>"']/g,
    (char) =>
      ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[
        char
      ],
  );
const paths = {
  arrow: '<path d="M5 12h14m-6-6 6 6-6 6"/>',
  back: '<path d="M19 12H5m6-6-6 6 6 6"/>',
  search: '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4.5 4.5"/>',
  calendar:
    '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 11h18m-13 4h2m4 0h2"/>',
  clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
  pin: '<path d="M20 10c0 6-8 11-8 11S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/>',
  people:
    '<circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3m1-16a3 3 0 0 1 0 6m2 4a5 5 0 0 1 3 5"/>',
  heart:
    '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z"/>',
  star: '<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2L12 17.3l-5.6 2.9 1.1-6.2L3 9.6l6.2-.9Z"/>',
  bell: '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9m-12 9h12m-8 4h4"/>',
  check: '<path d="m5 12 4 4L19 6"/>',
  plus: '<path d="M12 5v14M5 12h14"/>',
  edit: '<path d="m16 3 5 5-12 12-6 1 1-6L16 3Zm-2 2 5 5"/>',
  close: '<path d="m6 6 12 12M6 18 18 6"/>',
  user: '<circle cx="12" cy="8" r="4"/><path d="M4 22v-2a8 8 0 0 1 16 0v2"/>',
  globe:
    '<circle cx="12" cy="12" r="9"/><ellipse cx="12" cy="12" rx="4" ry="9"/><path d="M3 12h18"/>',
  download: '<path d="M12 3v12m-5-5 5 5 5-5M4 17v4h16v-4"/>',
  logout: '<path d="M9 4H4v16h5m5-14 6 6-6 6m-5-6h11"/>',
  filter: '<path d="M4 6h16M7 12h10m-7 6h4"/>',
  shield:
    '<path d="m12 3 9 4v6c0 5-9 9-9 9s-9-4-9-9V7l9-4Z"/><path d="m8 12 3 3 5-6"/>',
  menu: '<path d="M4 6h16M4 12h16M4 18h16"/>',
};
export const icon = (name, cls = "") =>
  `<svg class="icon ${cls}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths[name] || paths.calendar}</svg>`;
export function date(value, options = {}) {
  if (!value) return "—";
  const input = value.includes("T") ? value : value.replace(" ", "T") + "Z";
  return new Date(input).toLocaleString("ru-RU", {
    day: "numeric",
    month: "long",
    hour: "2-digit",
    minute: "2-digit",
    ...options,
  });
}
export const statusLabels = {
  draft: "Черновик",
  published: "Опубликовано",
  cancelled: "Отменено",
  planned: "Запланировано",
  ongoing: "Сейчас идёт",
  completed: "Завершено",
};
export const statusBadge = (event) =>
  `<span class="badge status-${esc(event.display_status || event.status)}">${esc(statusLabels[event.display_status || event.status] || event.status)}</span>`;
export const roleLabel = (role) =>
  ({
    student: "Студент",
    representative: "Представитель Комьюнити",
    admin: "Администратор",
  })[role] || role;
export function avatar(user, size = "") {
  const url = fileUrl(user?.avatar_url);
  return `<span class="avatar ${size}">${url ? `<img src="${esc(url)}" alt="" />` : esc(user?.name?.[0]?.toUpperCase() || "М")}</span>`;
}
export const options = (items, value, label = "Все") =>
  `<option value="">${esc(label)}</option>` +
  items
    .map(
      (item) =>
        `<option value="${item.id}" ${String(item.id) === String(value) ? "selected" : ""}>${esc(item.name)}</option>`,
    )
    .join("");
export function header() {
  const user = state.user;
  return `<header class="site-header"><div class="container header-inner">
    <a href="/" data-link class="brand" aria-label="На связи — главная"><span class="brand-symbol">${icon("calendar")}</span><span>на связи<span class="brand-caption">АФИША МРК</span></span></a>
    <nav class="public-nav" aria-label="Основная навигация"><a href="/" data-link class="${location.pathname === "/" ? "active" : ""}">Афиша</a><a href="/communities" data-link class="${location.pathname === "/communities" ? "active" : ""}">Сообщества</a><a href="/#events" data-link aria-label="Поиск по событиям">${icon("search")}Поиск</a></nav>
    <details class="mobile-nav"><summary aria-label="Открыть навигацию">${icon("menu")}</summary><nav aria-label="Навигация на телефоне"><a href="/" data-link>Афиша</a><a href="/communities" data-link>Сообщества</a><a href="/#events" data-link>Поиск по событиям</a></nav></details><div class="header-actions">${
      user
        ? `<a class="notification-button" href="/me/notifications" data-link aria-label="Уведомления${state.unread ? ", есть непрочитанные" : ""}">${icon("bell")}${state.unread ? '<span class="notification-dot"></span>' : ""}</a>
    <details class="user-menu"><summary>${avatar(user)}<span class="user-name">${esc(user.name.split(" ")[0])}</span><span class="chevron">⌄</span></summary><nav aria-label="Меню аккаунта"><span class="menu-role">${esc(roleLabel(user.role))}</span><a href="/me" data-link>Профиль</a><a href="/me/registrations" data-link>Мои регистрации</a><a href="/me/reactions" data-link>Мои реакции</a><a href="/me/notifications" data-link>Уведомления</a>${isRepresentative() ? '<a href="/me/events" data-link>Мои мероприятия</a>' : ""}${isAdmin() ? '<a href="/admin" data-link>Администрирование</a>' : ""}<button data-logout>Выйти ${icon("logout")}</button></nav></details>`
        : '<a href="/login" data-link class="login-link">Войти</a><a href="/register" data-link class="button button-small">Присоединиться ' +
          icon("arrow") +
          "</a>"
    }</div>
  </div></header>${user ? `<nav class="account-shortcuts" aria-label="Разделы аккаунта"><div class="container"><a href="/me" data-link>${icon("user")}Кабинет</a><a href="/me/registrations" data-link>${icon("calendar")}Мои регистрации</a><a href="/me/reactions" data-link>${icon("heart")}Мои реакции</a><a href="/me/notifications" data-link>${icon("bell")}Уведомления</a>${isRepresentative() ? `<a href="/me/events" data-link>${icon("edit")}Мои мероприятия</a>` : ""}${isAdmin() ? `<a href="/admin#verification" data-link>${icon("shield")}Верификация</a>` : ""}</div></nav>` : ""}`;
}
export function footer() {
  return `<footer class="site-footer"><div class="container footer-inner"><a class="footer-brand" href="/" data-link>на связи<span>Студенческая жизнь МРК</span></a><p>Автор: Максимчик Авелина</p><a href="mailto:avelina58100@gmail.com">avelina58100@gmail.com ${icon("arrow")}</a></div><div class="container footer-bottom"><span>© ${new Date().getFullYear()} · Афиша студенческих сообществ</span><span>МРК · Учебный конкурсный проект</span></div></footer>`;
}
export function cabinet(content, current = location.pathname) {
  const items = [
    ["/", "back", "На главную"],
    ["/me", "user", "Профиль"],
    ["/me/registrations", "calendar", "Мои регистрации"],
    ["/me/reactions", "heart", "Мои реакции"],
    ["/me/notifications", "bell", "Уведомления"],
  ];
  if (isRepresentative()) items.push(["/me/events", "edit", "Мои мероприятия"]);
  if (isAdmin()) items.push(["/admin", "shield", "Администрирование"]);
  return `<div class="container cabinet"><aside class="cabinet-sidebar"><div class="sidebar-user">${avatar(state.user, "avatar-large")}<strong>${esc(state.user.name)}</strong><span>${esc(roleLabel(state.user.role))}</span></div><nav aria-label="Личный кабинет">${items.map(([url, ico, label]) => `<a data-link href="${url}" class="${current === url || (url === "/me/events" && current.startsWith("/me/events/")) ? "active" : ""}">${icon(ico)}${label}</a>`).join("")}</nav><button data-logout class="sidebar-logout">${icon("logout")}Выйти из аккаунта</button></aside><section class="cabinet-main">${content}</section></div>`;
}
export function pageHeading(eyebrow, title, subtitle = "", action = "") {
  return `<div class="page-heading"><div><p class="eyebrow">${esc(eyebrow)}</p><h1>${esc(title)}</h1>${subtitle ? `<p class="subtitle">${esc(subtitle)}</p>` : ""}</div>${action}</div>`;
}
export function empty(
  title = "Здесь пока пусто",
  text = "Попробуйте изменить фильтры или загляните позже.",
  action = "",
) {
  return `<div class="empty-state"><span class="empty-symbol">${icon("calendar")}</span><h2>${esc(title)}</h2><p>${esc(text)}</p>${action}</div>`;
}
export const loading = () =>
  '<div class="loading" role="status"><span class="spinner"></span>Загружаем…</div>';
export function errorBox(error) {
  return `<div class="notice notice-error" role="alert"><strong>${error.status === 404 ? "Страница не найдена" : "Не удалось загрузить данные"}</strong><p>${esc(friendly(error))}</p><button class="button button-small button-outline" data-retry>Попробовать снова</button></div>`;
}
export function friendly(error) {
  return (
    {
      ALREADY_REGISTERED: "Вы уже записаны на это мероприятие.",
      EVENT_FULL:
        "Все места уже заняты. Можно отметить «Интересно», чтобы сохранить событие.",
      EVENT_STARTED:
        "Событие уже началось. Записаться или отменить регистрацию больше нельзя.",
      EVENT_CANCELLED: "Организатор отменил мероприятие.",
      USER_BLOCKED: "Ваш аккаунт заблокирован. Обратитесь к администратору.",
      FORBIDDEN: "Для этого действия недостаточно прав.",
      NOT_VERIFIED: "Сначала подтвердите email по ссылке из письма.",
      NOT_FOUND: "Эта страница не существует или недоступна вашему аккаунту.",
      UNAUTHORIZED: "Войдите в аккаунт, чтобы продолжить.",
    }[error.code] || error.message
  );
}
export function toast(message, error = false) {
  const element = document.createElement("div");
  element.className = `toast ${error ? "toast-error" : ""}`;
  element.innerHTML = `${icon(error ? "close" : "check")}<span>${esc(message)}</span><button aria-label="Закрыть">${icon("close")}</button>`;
  document.querySelector("#toasts").append(element);
  element.querySelector("button").onclick = () => element.remove();
  setTimeout(() => element.remove(), 6500);
}
// fields из API связываем с полями формы по name.
export function formError(form, error) {
  form.querySelectorAll(".field-error").forEach((node) => node.remove());
  form
    .querySelectorAll("[aria-invalid]")
    .forEach((node) => node.removeAttribute("aria-invalid"));
  const summary = form.querySelector("[data-form-error]");
  if (summary) {
    summary.textContent = friendly(error);
    summary.hidden = false;
  }
  for (const [name, messages] of Object.entries(error.fields || {})) {
    const input = form.elements.namedItem(name);
    if (!(input instanceof HTMLElement)) continue;
    input.setAttribute("aria-invalid", "true");
    const message = document.createElement("span");
    message.className = "field-error";
    message.textContent = messages.join(" ");
    input.closest(".field")?.append(message);
  }
  form.querySelector("[aria-invalid]")?.focus();
}
export function busy(form, value) {
  form.querySelectorAll('button[type="submit"]').forEach((button) => {
    button.disabled = value;
  });
  form.setAttribute("aria-busy", String(value));
}
export const formAlert =
  '<div class="notice notice-error" data-form-error role="alert" hidden></div>';
export function pager(meta) {
  const pages = Math.ceil(meta.total / meta.per_page);
  if (pages <= 1) return "";
  return `<nav class="pagination" aria-label="Страницы списка"><button data-page="${meta.page - 1}" ${meta.page === 1 ? "disabled" : ""} aria-label="Предыдущая страница">${icon("back")}</button><span>Страница <strong>${meta.page}</strong> из ${pages}</span><button data-page="${meta.page + 1}" ${meta.page >= pages ? "disabled" : ""} aria-label="Следующая страница">${icon("arrow")}</button></nav>`;
}
export function bindPager(meta, callback) {
  document
    .querySelectorAll("[data-page]")
    .forEach(
      (button) =>
        (button.onclick = () => callback(Number(button.dataset.page))),
    );
}
export function highlight(value, query) {
  const original = String(value ?? "");
  if (typeof query !== "string" || !query.trim()) return esc(original);
  const normalize = (value) =>
    value.toLocaleLowerCase("ru-RU").replaceAll("ё", "е");
  const needle = normalize(query.trim()),
    haystack = normalize(original);
  let from = 0,
    html = "",
    index = haystack.indexOf(needle);
  while (index !== -1) {
    html +=
      esc(original.slice(from, index)) +
      "<mark>" +
      esc(original.slice(index, index + needle.length)) +
      "</mark>";
    from = index + needle.length;
    index = haystack.indexOf(needle, from);
  }
  return html + esc(original.slice(from));
}
export function eventCard(event, query = "") {
  if (typeof query !== "string") query = "";
  const normalized = query
    .trim()
    .toLocaleLowerCase("ru-RU")
    .replaceAll("ё", "е");
  const titleMatches = event.title
    .toLocaleLowerCase("ru-RU")
    .replaceAll("ё", "е")
    .includes(normalized);
  let snippet = "";
  if (normalized && !titleMatches) {
    const description = event.description || "";
    const at = description
      .toLocaleLowerCase("ru-RU")
      .replaceAll("ё", "е")
      .indexOf(normalized);
    if (at >= 0) {
      const start = Math.max(0, at - 35),
        end = Math.min(description.length, at + normalized.length + 65);
      snippet =
        '<p class="search-excerpt"><span>В описании:</span> ' +
        (start ? "…" : "") +
        highlight(description.slice(start, end), query) +
        (end < description.length ? "…" : "") +
        "</p>";
    }
  }
  const image = fileUrl(event.cover_url);
  return `<article class="event-card"><a href="/events/${event.id}" data-link class="event-cover cover-club-${event.club_id}">${image ? `<img src="${esc(image)}" alt="" loading="lazy" />` : '<span class="cover-fallback">' + icon("calendar") + "</span>"}<span class="cover-badges"><span class="badge badge-paper">${esc(event.category_name)}</span>${event.display_status !== "planned" ? statusBadge(event) : ""}</span><span class="cover-date"><b>${new Date(event.starts_at).getDate()}</b><span>${new Date(event.starts_at).toLocaleString("ru-RU", { month: "short" }).replace(".", "")}</span></span></a>
    <div class="event-card-body"><a href="/?club_id=${event.club_id}" data-link class="club-label"><span class="club-dot"></span>${esc(event.club_name)}</a><h3><a href="/events/${event.id}" data-link>${highlight(event.title, query)}</a></h3>${snippet}<div class="event-meta"><span>${icon("clock")}${date(event.starts_at)}</span><span>${icon(event.format === "online" ? "globe" : "pin")}${esc(event.location)}</span></div>
    <div class="event-capacity"><div class="capacity-caption"><span>${event.capacity === null ? "Количество мест не ограничено" : event.free_places === 0 ? "Все места заняты" : "Осталось " + event.free_places + " из " + event.capacity + " мест"}</span><span>${event.format === "online" ? "Онлайн" : "Офлайн"}</span></div>${event.capacity ? `<div class="progress"><span style="width:${Math.min(100, (100 * event.registered_count) / event.capacity)}%"></span></div>` : ""}</div>
    <div class="card-bottom"><span class="card-counters"><span>${icon("people")}${event.registered_count}</span><span class="${event.my_reactions?.includes("like") ? "is-active" : ""}">${icon("heart")}${event.likes_count}</span><span class="${event.my_reactions?.includes("interested") ? "is-active" : ""}">${icon("star")}${event.interested_count}</span></span>${event.is_registered ? '<span class="registered-label">' + icon("check") + "Вы идёте</span>" : `<a class="card-link" href="/events/${event.id}" data-link aria-label="Подробнее: ${esc(event.title)}">${icon("arrow")}</a>`}</div></div></article>`;
}
// Диалог возвращает результат выбора; запрос выполняется только после подтверждения.
export function ask({
  title,
  text = "",
  reason = false,
  confirm = "Подтвердить",
  danger = false,
}) {
  return new Promise((resolve) => {
    const root = document.querySelector("#modal-root");
    root.innerHTML = `<dialog class="dialog"><form><button type="button" class="dialog-close icon-button" aria-label="Закрыть">${icon("close")}</button><p class="eyebrow">Подтверждение</p><h2>${esc(title)}</h2><p class="subtitle">${esc(text)}</p>${reason ? '<label class="field">Причина отмены<textarea name="reason" required maxlength="3000" rows="3" placeholder="Сообщите участникам, что изменилось"></textarea></label>' : ""}<div class="dialog-actions"><button type="button" class="button button-outline" data-dismiss>Назад</button><button type="submit" class="button ${danger ? "button-danger" : ""}">${esc(confirm)}</button></div></form></dialog>`;
    const dialog = root.querySelector("dialog");
    const previous = document.activeElement;
    const finish = (value) => {
      dialog.close();
      root.innerHTML = "";
      previous?.focus?.();
      resolve(value);
    };
    dialog.querySelector("form").onsubmit = (e) => {
      e.preventDefault();
      finish(
        reason ? dialog.querySelector("textarea").value.trim() || false : true,
      );
    };
    dialog
      .querySelectorAll("[data-dismiss],.dialog-close")
      .forEach((button) => (button.onclick = () => finish(false)));
    dialog.oncancel = (e) => {
      e.preventDefault();
      finish(false);
    };
    dialog.onclick = (e) => {
      if (e.target === dialog) {
        const rect = dialog.getBoundingClientRect();
        if (
          e.clientX < rect.left ||
          e.clientX > rect.right ||
          e.clientY < rect.top ||
          e.clientY > rect.bottom
        )
          finish(false);
      }
    };
    dialog.showModal();
  });
}

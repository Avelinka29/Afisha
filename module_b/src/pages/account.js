import { googleButton, bindGoogle } from "../google.js";
import { api, upload, fileUrl, ApiError } from "../api.js";
import { state } from "../state.js";
import { navigate } from "../router.js";
import {
  esc,
  icon,
  cabinet,
  pageHeading,
  avatar,
  roleLabel,
  formAlert,
  formError,
  busy,
  toast,
  eventCard,
  empty,
  pager,
  bindPager,
  date,
  ask,
  friendly,
} from "../ui.js";
import { bindPasswords } from "./auth.js";
export async function profilePage() {
  const { data: user } = await api("/me");
  state.user = user;
  return {
    title: "Профиль",
    html: cabinet(
      `${pageHeading("Личный кабинет", "Привет, " + user.name.split(" ")[0] + "!", "Здесь всё, что связано с тобой и твоими планами.")}${user.blocked ? '<div class="notice notice-error">Аккаунт заблокирован. Изменения и запись на события недоступны. Обратитесь к администратору.</div>' : ""}<div class="profile-overview">${avatar(user, "avatar-xl")}<div><h2>${esc(user.name)}</h2><p>${esc(user.email)}</p><span class="badge badge-green">${esc(roleLabel(user.role))}</span></div></div><section class="panel"><h2>Личные данные</h2><form id="profile-form">${formAlert}<div class="form-row"><label class="field">Имя<input name="name" required maxlength="120" value="${esc(user.name)}" autocomplete="name" /></label><label class="field">Email<input type="email" disabled value="${esc(user.email)}" /><span class="field-hint">Email изменить нельзя</span></label></div><div class="form-row"><label class="field">Специальность<input name="faculty" required maxlength="190" value="${esc(user.faculty)}" /></label><label class="field course-field">Курс<select name="course" required>${Array.from({ length: 4 }, (_, i) => `<option value="${i + 1}" ${Number(user.course) === i + 1 ? "selected" : ""}>${i + 1} курс</option>`).join("")}</select></label></div><label class="field">Аватар: ссылка на изображение<input name="avatar_url" value="${esc(user.avatar_url)}" placeholder="https://…" /></label><label class="field">Или загрузи фотографию<input type="file" name="file" accept="image/png,image/jpeg,image/webp" /><span class="field-hint">PNG, JPEG или WebP до 4 МБ. Без фото показывается первая буква имени.</span></label><button type="submit" class="button">Сохранить изменения ${icon("check")}</button></form></section><section class="panel" id="communities"><h2>Мои сообщества и верификация</h2><p class="subtitle">Публиковать события можно только от имени сообщества, где ты верифицирована.</p>${user.clubs.length ? `<div class="membership-list">${user.clubs.map((club) => `<div><span>${esc(club.name)}</span><span class="badge ${club.verified ? "badge-green" : ""}">${club.verified ? icon("check") + "Верифицирован" : "Не верифицирован"}</span></div>`).join("")}</div>` : '<p class="muted">Пока нет привязки к сообществам. Для получения прав представителя обратись к администратору.</p>'}</section><section class="panel"><h2>Вход через Google</h2><p class="subtitle">${user.google_connected ? "Google привязан. Можно входить без ввода пароля." : "Привяжи свой Google, чтобы входить в этот профиль одним нажатием."}</p>${user.google_connected ? `<span class="badge badge-green">${icon("check")}Google подключён</span>` : googleButton("link")}</section><section class="panel"><h2>Безопасность</h2><form id="password-form">${formAlert}<label class="field">Текущий пароль<input name="current_password" type="password" required maxlength="72" autocomplete="current-password" /></label><div class="form-row"><label class="field">Новый пароль<input name="password" type="password" required minlength="8" maxlength="72" autocomplete="new-password" /></label><label class="field">Повтори новый пароль<input name="password_confirmation" type="password" required minlength="8" maxlength="72" autocomplete="new-password" /></label></div><button type="submit" class="button button-outline">Изменить пароль</button></form></section>`,
    ),
    bind() {
      bindGoogle();
      const form = document.querySelector("#profile-form");
      form.onsubmit = async (e) => {
        e.preventDefault();
        busy(form, true);
        try {
          const data = Object.fromEntries(new FormData(form));
          const image = await upload(data.file);
          delete data.file;
          data.course = Number(data.course);
          data.avatar_url = image || data.avatar_url || null;
          state.user = (await api("/me", { method: "PATCH", data })).data;
          toast("Профиль сохранён");
          window.dispatchEvent(
            new CustomEvent("route-change", { detail: { keepScroll: true } }),
          );
        } catch (error) {
          formError(form, error);
        } finally {
          busy(form, false);
        }
      };
      const password = document.querySelector("#password-form");
      password.onsubmit = async (e) => {
        e.preventDefault();
        busy(password, true);
        try {
          const data = Object.fromEntries(new FormData(password));
          if (data.password !== data.password_confirmation)
            throw new ApiError(422, {
              message: "Пароли не совпадают",
              fields: { password_confirmation: ["Пароли должны совпадать"] },
            });
          await api("/me/password", { method: "POST", data });
          password.reset();
          toast("Пароль изменён");
        } catch (error) {
          formError(password, error);
        } finally {
          busy(password, false);
        }
      };
    },
  };
}
export async function registrationsPage() {
  const params = new URLSearchParams(location.search);
  const period = params.get("period") || "upcoming";
  const page = params.get("page") || 1;
  const list = await api(
    `/me/registrations?period=${encodeURIComponent(period)}&page=${page}&per_page=6`,
  );
  return {
    title: "Мои регистрации",
    html: cabinet(
      `${pageHeading("Твои планы", "Мои регистрации", "События, на которых тебя ждут.")}<div class="tabs"><a href="/me/registrations?period=upcoming" data-link class="${period === "upcoming" ? "active" : ""}">Предстоящие</a><a href="/me/registrations?period=past" data-link class="${period === "past" ? "active" : ""}">Прошедшие</a></div>${list.data.length ? `<div class="cabinet-events-grid">${list.data.map((reg) => `<div class="registration-item">${eventCard(reg.event)}<div class="registration-actions"><span class="badge ${reg.status === "active" ? "badge-green" : "badge-red"}">${reg.status === "active" ? "Регистрация активна" : "Отменено организатором"}</span>${reg.status === "active" && new Date(reg.event.starts_at) > new Date() && reg.event.status === "published" ? `<button class="text-button danger-text" data-unregister="${reg.event_id}">Отменить регистрацию</button>` : ""}</div></div>`).join("")}</div>` : empty(period === "past" ? "История только начинается" : "Пока без планов", period === "past" ? "Здесь появятся завершённые мероприятия, на которые ты была записана." : "Загляни в афишу — там есть повод встретиться.", '<a class="button" href="/" data-link>Выбрать событие ' + icon("arrow") + "</a>")}${pager(list.meta)}`,
    ),
    bind() {
      bindPager(list.meta, (page) =>
        navigate(`/me/registrations?period=${period}&page=${page}`),
      );
      document.querySelectorAll("[data-unregister]").forEach(
        (button) =>
          (button.onclick = async () => {
            if (
              !(await ask({
                title: "Отменить регистрацию?",
                text: "Твоё место станет доступно другим студентам.",
                confirm: "Отменить регистрацию",
                danger: true,
              }))
            )
              return;
            button.disabled = true;
            try {
              await api("/events/" + button.dataset.unregister + "/register", {
                method: "DELETE",
                data: {},
              });
              toast("Регистрация отменена");
              window.dispatchEvent(
                new CustomEvent("route-change", {
                  detail: { keepScroll: true },
                }),
              );
            } catch (error) {
              toast(friendly(error), true);
              button.disabled = false;
            }
          }),
      );
    },
  };
}
export async function reactionsPage() {
  const page = new URLSearchParams(location.search).get("page") || 1;
  const list = await api(`/me/reactions?page=${page}&per_page=6`);
  return {
    title: "Мои реакции",
    html: cabinet(
      `${pageHeading("Сохрани на потом", "Мои реакции", "Всё, что понравилось или показалось интересным.")}${list.data.length ? `<div class="reaction-list">${list.data.map((row) => `<article class="saved-event"><a href="/events/${row.event_id}" data-link class="saved-cover">${fileUrl(row.event.cover_url) ? `<img src="${esc(fileUrl(row.event.cover_url))}" alt="" />` : icon("calendar")}</a><div class="saved-content"><span class="club-label">${esc(row.event.club_name)}</span><h2><a href="/events/${row.event_id}" data-link>${esc(row.event.title)}</a></h2><p>${date(row.event.starts_at)}</p><span class="badge badge-green">${icon(row.type === "like" ? "heart" : "star")}${row.type === "like" ? "Нравится" : "Интересно"}</span><small>Отмечено ${date(row.created_at)}</small></div><button class="icon-button" data-remove-reaction="${row.event_id}" data-type="${row.type}" aria-label="Снять реакцию с ${esc(row.event.title)}">${icon("close")}</button></article>`).join("")}</div>` : empty("Твои находки будут здесь", "Отмечай события кнопками «Нравится» и «Интересно».", '<a class="button" href="/" data-link>Перейти к афише ' + icon("arrow") + "</a>")}${pager(list.meta)}`,
    ),
    bind() {
      bindPager(list.meta, (page) => navigate("/me/reactions?page=" + page));
      document.querySelectorAll("[data-remove-reaction]").forEach(
        (button) =>
          (button.onclick = async () => {
            button.disabled = true;
            try {
              await api(
                `/events/${button.dataset.removeReaction}/reactions?type=${button.dataset.type}`,
                { method: "DELETE" },
              );
              toast("Реакция снята");
              window.dispatchEvent(
                new CustomEvent("route-change", {
                  detail: { keepScroll: true },
                }),
              );
            } catch (error) {
              toast(friendly(error), true);
              button.disabled = false;
            }
          }),
      );
    },
  };
}
// Ссылка на событие берётся из payload уведомления, полученного от API.
export async function notificationsPage() {
  const page = new URLSearchParams(location.search).get("page") || 1;
  const list = await api(`/me/notifications?page=${page}&per_page=12`);
  const types = {
    event_cancelled: ["close", "Событие отменено"],
    event_reminder: ["clock", "Скоро встречаемся"],
    event_changed: ["edit", "Изменения в событии"],
    event_full: ["people", "Все места заняты"],
    registration_confirmed: ["check", "Ты в списке"],
  };
  return {
    title: "Уведомления",
    html: cabinet(
      `${pageHeading("Ничего не потеряется", "Уведомления", "Подтверждения, напоминания и важные изменения.")}${
        list.data.length
          ? `<div class="notification-list">${list.data
              .map((row) => {
                const [ico, label] = types[row.type] || ["bell", "Уведомление"];
                return `<article class="notification-item ${row.read_at ? "" : "unread"}"><span class="notification-type type-${esc(row.type)}">${icon(ico)}</span><div><span class="eyebrow">${label}</span><a data-link data-notification="${row.id}" href="/events/${Number(row.payload.event_id)}"><h2>${esc(row.message)}</h2></a><time>${date(row.created_at)}</time></div>${!row.read_at ? `<button class="icon-button" data-read="${row.id}" aria-label="Отметить уведомление прочитанным">${icon("check")}</button>` : ""}</article>`;
              })
              .join("")}</div>`
          : empty(
              "Всё спокойно",
              "Когда появятся новости о твоих событиях, они будут здесь.",
            )
      }${pager(list.meta)}`,
    ),
    bind() {
      bindPager(list.meta, (page) =>
        navigate("/me/notifications?page=" + page),
      );
      document.querySelectorAll("[data-read]").forEach(
        (button) =>
          (button.onclick = async () => {
            button.disabled = true;
            try {
              await api("/me/notifications/" + button.dataset.read + "/read", {
                method: "PATCH",
                data: {},
              });
              window.dispatchEvent(
                new CustomEvent("route-change", {
                  detail: { keepScroll: true },
                }),
              );
            } catch (error) {
              toast(friendly(error), true);
              button.disabled = false;
            }
          }),
      );
      document.querySelectorAll("[data-notification]").forEach((link) =>
        link.addEventListener("click", () => {
          api("/me/notifications/" + link.dataset.notification + "/read", {
            method: "PATCH",
            data: {},
          }).catch(() => {});
        }),
      );
    },
  };
}

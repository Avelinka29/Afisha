import { api } from "../api.js";
import { state } from "../state.js";
import { navigate } from "../router.js";
import {
  esc,
  icon,
  cabinet,
  pageHeading,
  options,
  pager,
  bindPager,
  empty,
  date,
  formAlert,
  formError,
  busy,
  toast,
  friendly,
  ask,
} from "../ui.js";
// Меню — только интерфейс. Каждый административный запрос также проверяется PHP.
export async function adminPage() {
  const params = new URLSearchParams(location.search),
    tab = params.get("tab") || "users",
    page = params.get("page") || 1;
  const tabs = `<div class="tabs">${[
    ["users", "Пользователи"],
    ["clubs", "Сообщества"],
    ["categories", "Категории"],
    ["moderation", "Модерация"],
    ["mail", "Почта"],
  ]
    .map(
      ([value, label]) =>
        `<a href="/admin?tab=${value}" data-link class="${tab === value ? "active" : ""}">${label}</a>`,
    )
    .join("")}</div>`;
  let content = "",
    bind = () => {};
  if (tab === "mail") {
    const { data: mail } = await api("/admin/mail");
    content = `<section class="panel"><h2>Настоящая отправка писем</h2><p class="subtitle">Письма подтверждения и восстановления будут отправляться с <strong>${esc(mail.sender)}</strong>.</p><div class="notice ${mail.delivery_tested_at ? "" : "notice-error"}"><strong>${mail.delivery_tested_at ? "Проверка доставки пройдена" : mail.connected ? "Доступ Gmail получен, отправку нужно проверить" : "Отправка Gmail ещё не подключена"}</strong><p>${mail.delivery_tested_at ? "Последняя успешная отправка: " + date(mail.delivery_tested_at) : "Владелец почты один раз разрешает приложению отправлять письма. После подключения автоматически уйдёт настоящее тестовое письмо."}</p></div><p class="subtitle">Используется Gmail API по HTTPS. Для подключения выбери Google-аккаунт отправителя и разреши отправку писем.</p><div class="form-footer"><button id="connect-gmail" class="button">${icon("globe")}${mail.connected ? "Подключить заново" : "Подключить Gmail"}</button>${mail.connected ? '<button id="test-gmail" class="button button-outline">Отправить тестовое письмо</button>' : ""}</div><p class="field-hint">Если Google сообщает, что Gmail API выключен, включи его в проекте Afisha и повтори проверку.</p><a href="https://console.cloud.google.com/apis/library/gmail.googleapis.com" target="_blank" rel="noopener noreferrer" class="text-link">Открыть настройки Gmail API ${icon("arrow")}</a></section>`;
    bind = () => {
      document.querySelector("#connect-gmail").onclick = async (e) => {
        const button = e.currentTarget;
        button.disabled = true;
        try {
          const result = await api("/admin/mail/google/url");
          window.location.assign(result.data.url);
        } catch (error) {
          toast(friendly(error), true);
          button.disabled = false;
        }
      };
      const test = document.querySelector("#test-gmail");
      if (test)
        test.onclick = async () => {
          test.disabled = true;
          try {
            const result = await api("/admin/mail/test", {
              method: "POST",
              data: {},
            });
            toast(result.message);
            window.dispatchEvent(
              new CustomEvent("route-change", { detail: { keepScroll: true } }),
            );
          } catch (error) {
            toast(friendly(error), true);
            test.disabled = false;
          }
        };
    };
  } else if (tab === "users") {
    const [list, all] = await Promise.all([
      api("/admin/users?page=" + page + "&per_page=12"),
      api("/admin/users?per_page=100"),
    ]);
    content = `<div class="table-scroll"><table><thead><tr><th>Пользователь</th><th>Роль</th><th>Состояние</th><th>Действие</th></tr></thead><tbody>${list.data.map((user) => `<tr><td><strong>${esc(user.name)}</strong><small>${esc(user.email)}</small></td><td>${user.role === "admin" ? "Администратор" : "Студент"}</td><td><span class="badge ${Number(user.blocked) ? "badge-red" : "badge-green"}">${Number(user.blocked) ? "Заблокирован" : "Активен"}</span></td><td>${Number(user.id) !== Number(state.user.id) ? `<button class="text-button ${Number(user.blocked) ? "" : "danger-text"}" data-block="${user.id}" data-value="${Number(user.blocked) ? "false" : "true"}">${Number(user.blocked) ? "Разблокировать" : "Заблокировать"}</button>` : "Твой аккаунт"}</td></tr>`).join("")}</tbody></table></div>${pager(list.meta)}<section class="panel" id="verification"><h2>Верификация представителя</h2><p class="subtitle">Права выдаются для конкретного сообщества. Их можно отозвать в любой момент.</p><form id="verification-form">${formAlert}<div class="form-row"><label class="field">Студент<select name="user_id" required>${options(
      all.data
        .filter((u) => u.role !== "admin")
        .map((u) => ({ ...u, name: u.name + " · " + u.email })),
      null,
      "Выбрать студента",
    )}</select></label><label class="field">Сообщество<select name="club_id" required>${options(state.clubs, null, "Выбрать сообщество")}</select></label></div><label class="field">Действие<select name="verified"><option value="true">Выдать верификацию</option><option value="false">Отозвать верификацию</option></select></label><button type="submit" class="button">Сохранить ${icon("check")}</button></form></section>`;
    bind = () => {
      bindPager(list.meta, (page) => navigate("/admin?tab=users&page=" + page));
      document.querySelectorAll("[data-block]").forEach(
        (button) =>
          (button.onclick = async () => {
            const block = button.dataset.value === "true";
            if (
              !(await ask({
                title: block
                  ? "Заблокировать пользователя?"
                  : "Восстановить доступ?",
                text: block
                  ? "Пользователь потеряет доступ к действиям в приложении."
                  : "Пользователь снова сможет пользоваться приложением.",
                confirm: block ? "Заблокировать" : "Разблокировать",
                danger: block,
              }))
            )
              return;
            button.disabled = true;
            try {
              await api("/admin/users/" + button.dataset.block + "/block", {
                method: "PATCH",
                data: { blocked: block },
              });
              toast("Статус пользователя обновлён");
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
      const form = document.querySelector("#verification-form");
      form.onsubmit = async (e) => {
        e.preventDefault();
        busy(form, true);
        try {
          const d = Object.fromEntries(new FormData(form));
          await api(`/admin/clubs/${d.club_id}/representatives/${d.user_id}`, {
            method: "PUT",
            data: { verified: d.verified === "true" },
          });
          toast(
            d.verified === "true"
              ? "Верификация выдана"
              : "Верификация отозвана",
          );
        } catch (error) {
          formError(form, error);
        } finally {
          busy(form, false);
        }
      };
    };
  } else if (["clubs", "categories"].includes(tab)) {
    const { data: rows } = await api("/admin/" + tab);
    const edit = rows.find((row) => String(row.id) === params.get("edit"));
    content = `<div class="directory-list">${rows.map((row) => `<div><div><h2>${esc(row.name)}</h2>${row.description ? `<p>${esc(row.description)}</p>` : ""}</div><a href="/admin?tab=${tab}&edit=${row.id}" data-link class="icon-button" aria-label="Редактировать ${esc(row.name)}">${icon("edit")}</a><button class="icon-button danger-text" data-delete-record="${row.id}" aria-label="Удалить ${esc(row.name)}">${icon("close")}</button></div>`).join("")}</div><section class="panel"><h2>${edit ? "Редактировать запись" : "Добавить " + (tab === "clubs" ? "сообщество" : "категорию")}</h2><form id="directory-form">${formAlert}<label class="field">Название<input name="name" required maxlength="${tab === "clubs" ? 150 : 120}" value="${esc(edit?.name)}" /></label>${tab === "clubs" ? `<label class="field">Описание<textarea name="description" required rows="3" maxlength="10000">${esc(edit?.description)}</textarea></label>` : ""}<div class="form-footer">${edit ? `<a data-link href="/admin?tab=${tab}" class="button button-outline">Отменить</a>` : ""}<button type="submit" class="button">${edit ? "Сохранить" : "Добавить"} ${icon("plus")}</button></div></form></section>`;
    bind = () => {
      const form = document.querySelector("#directory-form");
      form.onsubmit = async (e) => {
        e.preventDefault();
        busy(form, true);
        try {
          await api("/admin/" + tab + (edit ? "/" + edit.id : ""), {
            method: edit ? "PATCH" : "POST",
            data: Object.fromEntries(new FormData(form)),
          });
          const [clubs, categories] = await Promise.all([
            api("/clubs"),
            api("/categories"),
          ]);
          state.clubs = clubs.data;
          state.categories = categories.data;
          toast("Справочник обновлён");
          navigate("/admin?tab=" + tab);
        } catch (error) {
          formError(form, error);
        } finally {
          busy(form, false);
        }
      };
      document.querySelectorAll("[data-delete-record]").forEach(
        (button) =>
          (button.onclick = async () => {
            if (
              !(await ask({
                title: "Удалить запись?",
                text: "Удаление возможно только для записей, которые не используются в мероприятиях.",
                confirm: "Удалить",
                danger: true,
              }))
            )
              return;
            button.disabled = true;
            try {
              await api("/admin/" + tab + "/" + button.dataset.deleteRecord, {
                method: "DELETE",
              });
              toast("Запись удалена");
              state.clubs = (await api("/clubs")).data;
              state.categories = (await api("/categories")).data;
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
    };
  } else if (tab === "moderation") {
    const list = await api("/events?page=" + page + "&per_page=12");
    content = `<p class="subtitle">Опубликованные события всех сообществ. При отмене участники получат уведомление.</p>${list.data.length ? `<div class="management-list">${list.data.map((event) => `<article class="management-item"><span class="club-label">${esc(event.club_name)}</span><h2><a data-link href="/events/${event.id}">${esc(event.title)}</a></h2><p>${date(event.starts_at)}</p><button class="text-button danger-text" data-moderate="${event.id}">Отменить мероприятие</button></article>`).join("")}</div>` : empty("Нет опубликованных событий")}${pager(list.meta)}`;
    bind = () => {
      bindPager(list.meta, (page) =>
        navigate("/admin?tab=moderation&page=" + page),
      );
      document.querySelectorAll("[data-moderate]").forEach(
        (button) =>
          (button.onclick = async () => {
            const reason = await ask({
              title: "Отменить мероприятие?",
              text: "Причина будет отправлена зарегистрированным участникам.",
              reason: true,
              confirm: "Отменить",
              danger: true,
            });
            if (!reason) return;
            button.disabled = true;
            try {
              await api(
                "/admin/events/" + button.dataset.moderate + "/cancel",
                { method: "POST", data: { reason } },
              );
              toast("Мероприятие отменено");
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
    };
  } else content = empty("Такого раздела нет", "Выбери раздел в меню выше.");
  return {
    title: "Администрирование",
    html: cabinet(
      pageHeading(
        "Управление приложением",
        "Администрирование",
        "Пользователи, сообщества и порядок в афише.",
      ) +
        tabs +
        content,
    ),
    bind,
  };
}

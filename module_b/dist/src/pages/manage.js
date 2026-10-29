import { api, ApiError, upload, fileUrl, API } from "../api.js";
import { state } from "../state.js";
import { navigate } from "../router.js";
import {
  esc,
  icon,
  cabinet,
  pageHeading,
  options,
  empty,
  pager,
  bindPager,
  date,
  statusBadge,
  formAlert,
  formError,
  busy,
  toast,
  friendly,
  ask,
} from "../ui.js";
export function bindEventActions() {
  document.querySelectorAll("[data-event-action]").forEach(
    (button) =>
      (button.onclick = async () => {
        const id = button.dataset.id;
        const action = button.dataset.eventAction;
        let data = {};
        if (action === "cancel") {
          const reason = await ask({
            title: "Отменить мероприятие?",
            text: "Участники получат уведомление с указанной причиной.",
            reason: true,
            confirm: "Отменить мероприятие",
            danger: true,
          });
          if (!reason) return;
          data = { reason };
        } else if (action === "delete") {
          if (
            !(await ask({
              title: "Удалить черновик?",
              text: "Вернуть его после удаления не получится.",
              confirm: "Удалить",
              danger: true,
            }))
          )
            return;
        } else if (
          !(await ask({
            title: "Опубликовать мероприятие?",
            text: "Оно появится в афише, и студенты смогут записаться.",
            confirm: "Опубликовать",
          }))
        )
          return;
        button.disabled = true;
        try {
          await api(
            "/events/" + id + (action === "delete" ? "" : "/" + action),
            { method: action === "delete" ? "DELETE" : "POST", data },
          );
          toast(
            action === "delete"
              ? "Черновик удалён"
              : action === "cancel"
                ? "Мероприятие отменено"
                : "Мероприятие опубликовано",
          );
          window.dispatchEvent(
            new CustomEvent("route-change", { detail: { keepScroll: true } }),
          );
        } catch (error) {
          toast(friendly(error), true);
          button.disabled = false;
        }
      }),
  );
}
export async function managePage() {
  const params = new URLSearchParams(location.search);
  const status = params.get("status") || "";
  const page = params.get("page") || 1;
  const list = await api(
    `/me/events?page=${page}&per_page=8${status ? "&status=" + encodeURIComponent(status) : ""}`,
  );
  return {
    title: "Мои мероприятия",
    html: cabinet(
      `<a href="/" data-link class="back-link">${icon("back")}На главную</a>${pageHeading("Для организаторов", "Мои мероприятия", "События сообществ, в которых ты верифицирована.", `<a href="/me/events/new" data-link class="button">${icon("plus")}Создать событие</a>`)}<div class="tabs">${[
        ["", "Все"],
        ["draft", "Черновики"],
        ["published", "Опубликованные"],
        ["cancelled", "Отменённые"],
      ]
        .map(
          ([value, label]) =>
            `<a href="/me/events${value ? "?status=" + value : ""}" data-link class="${status === value ? "active" : ""}">${label}</a>`,
        )
        .join(
          "",
        )}</div>${list.data.length ? `<div class="management-list">${list.data.map((event) => `<article class="management-item"><div class="management-top"><div><span class="club-label">${esc(event.club_name)}</span><h2><a href="/events/${event.id}" data-link>${esc(event.title)}</a></h2><p>${icon("calendar")}${date(event.starts_at)}</p></div>${statusBadge(event)}</div><div class="management-bottom"><span class="card-counters"><span>${icon("people")}${event.registered_count}</span><span>${icon("heart")}${event.likes_count}</span><span>${icon("star")}${event.interested_count}</span></span><div class="management-actions"><a href="/me/events/${event.id}/edit" data-link>${icon("edit")}Редактировать</a><a href="/me/events/${event.id}/participants" data-link>${icon("people")}Участники</a>${event.status === "draft" ? `<button data-event-action="publish" data-id="${event.id}">${icon("check")}Опубликовать</button><button class="danger-text" data-event-action="delete" data-id="${event.id}">Удалить</button>` : ""}${event.status !== "cancelled" ? `<button class="danger-text" data-event-action="cancel" data-id="${event.id}">Отменить</button>` : ""}</div></div>${event.cancellation_reason ? `<p class="cancellation-reason">Причина отмены: ${esc(event.cancellation_reason)}</p>` : ""}</article>`).join("")}</div>` : empty("Здесь появятся твои события", "Создай первое мероприятие от имени своего сообщества.", '<a href="/me/events/new" data-link class="button">Создать событие ' + icon("plus") + "</a>")}${pager(list.meta)}`,
    ),
    bind() {
      bindEventActions();
      bindPager(list.meta, (page) =>
        navigate(`/me/events?page=${page}${status ? "&status=" + status : ""}`),
      );
    },
  };
}
// datetime-local использует местное время, а сервер принимает UTC.
function localInput(value) {
  if (!value) return "";
  const d = new Date(value);
  return new Date(d.getTime() - d.getTimezoneOffset() * 60000)
    .toISOString()
    .slice(0, 16);
}
export async function eventFormPage(id = null) {
  const event = id ? (await api("/events/" + id)).data : null;
  if (event && !event.can_manage)
    throw new ApiError(403, {
      code: "FORBIDDEN",
      message: "Нет прав на это мероприятие",
    });
  const clubs = state.user.clubs.filter((club) => club.verified);
  const html = cabinet(
    `<a href="/me/events" data-link class="back-link">${icon("back")}Мои мероприятия</a>${pageHeading("Собираем своих", event ? "Редактирование события" : "Новое событие", "Расскажи, где, когда и зачем встречаемся.")}<form id="event-form" class="panel event-form">${formAlert}<div class="form-row"><label class="field">Сообщество<select name="club_id" required ${event ? "disabled" : ""}>${options(clubs, event?.club_id, "Выбрать сообщество")}</select></label><label class="field">Категория<select name="category_id" required>${options(state.categories, event?.category_id, "Выбрать категорию")}</select></label></div><label class="field">Название<input name="title" required maxlength="200" value="${esc(event?.title)}" placeholder="Как назовём событие?" /></label><label class="field">Описание<textarea name="description" required maxlength="30000" rows="6" placeholder="Что будет происходить? Кому будет интересно? Что взять с собой?">${esc(event?.description)}</textarea></label><div class="form-row"><label class="field">Начало<input name="starts_at" type="datetime-local" required value="${localInput(event?.starts_at)}" ${event ? "" : `min="${localInput(new Date().toISOString())}"`} /></label><label class="field">Окончание<input name="ends_at" type="datetime-local" required value="${localInput(event?.ends_at)}" /></label></div><div class="form-row"><label class="field">Формат<select name="format"><option value="offline" ${event?.format !== "online" ? "selected" : ""}>Офлайн</option><option value="online" ${event?.format === "online" ? "selected" : ""}>Онлайн</option></select></label><label class="field">Вместимость<input name="capacity" type="number" min="1" max="1000000" step="1" value="${event?.capacity ?? ""}" placeholder="Без ограничения" /><span class="field-hint">Оставь пустым, если мест хватит всем.</span></label></div><label class="field"><span id="location-label">${event?.format === "online" ? "Ссылка на встречу" : "Место встречи"}</span><input name="location" required maxlength="1000" value="${esc(event?.location)}" placeholder="Корпус, аудитория или ссылка" /></label><div class="cover-form"><div><label class="field">Обложка: ссылка<input name="cover_url" value="${esc(event?.cover_url)}" maxlength="1000" placeholder="https://…" /></label><label class="field">Или загрузи изображение<input type="file" name="file" accept="image/png,image/jpeg,image/webp" /><span class="field-hint">JPEG, PNG, WebP до 4 МБ</span></label></div><div class="cover-preview" id="cover-preview">${fileUrl(event?.cover_url) ? `<img src="${esc(fileUrl(event.cover_url))}" alt="Предпросмотр обложки" />` : `${icon("calendar")}<span>Обложка события</span>`}</div></div>${event ? `<div class="form-status">Текущий статус: ${statusBadge(event)}<span>Сохранение не меняет статус опубликованного события.</span></div>` : '<p class="field-hint">«Сохранить черновик» оставит событие видимым только представителям твоего сообщества.</p>'}<div class="form-footer"><a href="/me/events" data-link class="button button-outline">Назад</a><button type="submit" class="button ${!event || event.status === "draft" ? "button-outline" : ""}" name="intent" value="save">${icon("check")}${event ? "Сохранить изменения" : "Сохранить черновик"}</button>${!event || event.status === "draft" ? '<button type="submit" class="button" name="intent" value="publish">Опубликовать ' + icon("arrow") + "</button>" : ""}</div></form>${event ? `<section class="panel"><h2>Управление событием</h2><div class="management-actions"><a href="/events/${event.id}" data-link>Открыть карточку ${icon("arrow")}</a><a href="/me/events/${event.id}/participants" data-link>Список участников ${icon("people")}</a>${event.status !== "cancelled" ? `<button class="danger-text" data-event-action="cancel" data-id="${event.id}">Отменить мероприятие</button>` : ""}${event.status === "draft" ? `<button class="danger-text" data-event-action="delete" data-id="${event.id}">Удалить черновик</button>` : ""}</div></section>` : ""}`,
  );
  return {
    title: event ? "Редактирование события" : "Новое событие",
    html,
    bind() {
      bindEventActions();
      const form = document.querySelector("#event-form");
      let previewUrl = null;
      form.format.onchange = () => {
        document.querySelector("#location-label").textContent =
          form.format.value === "online"
            ? "Ссылка на встречу"
            : "Место встречи";
        form.location.placeholder =
          form.format.value === "online" ? "https://…" : "Корпус, аудитория";
      };
      form.elements.file.onchange = () => {
        if (previewUrl) URL.revokeObjectURL(previewUrl);
        const file = form.elements.file.files[0];
        if (file) {
          previewUrl = URL.createObjectURL(file);
          document.querySelector("#cover-preview").innerHTML =
            `<img src="${previewUrl}" alt="Предпросмотр выбранной обложки" />`;
        }
      };
      form.onsubmit = async (e) => {
        e.preventDefault();
        const publish = e.submitter?.value === "publish";
        busy(form, true);
        try {
          const data = Object.fromEntries(new FormData(form));
          const start = new Date(data.starts_at),
            end = new Date(data.ends_at);
          if (end <= start)
            throw new ApiError(422, {
              message: "Окончание должно быть позже начала",
              fields: { ends_at: ["Укажи время после начала"] },
            });
          const sameStart =
            event && localInput(event.starts_at) === data.starts_at;
          if (start <= new Date() && (!event || !sameStart || publish))
            throw new ApiError(422, {
              message: "Начало должно быть в будущем",
              fields: { starts_at: ["Выбери будущее время"] },
            });
          if (data.format === "online" && !/^https?:\/\//i.test(data.location))
            throw new ApiError(422, {
              message: "Укажи ссылку на онлайн-встречу",
              fields: { location: ["Нужна ссылка http:// или https://"] },
            });
          if (id) delete data.club_id;
          else data.club_id = Number(data.club_id);
          data.category_id = Number(data.category_id);
          data.capacity = data.capacity ? Number(data.capacity) : null;
          data.starts_at = sameStart ? event.starts_at : start.toISOString();
          data.ends_at = end.toISOString();
          const image = await upload(data.file);
          delete data.file;
          data.cover_url = image || data.cover_url || null;
          const result = await api("/events" + (id ? "/" + id : ""), {
            method: id ? "PATCH" : "POST",
            data,
          });
          const eventId = result.data.id;
          // Если публикация не пройдёт, сохранённый черновик всё равно останется.
          if (publish) {
            try {
              await api("/events/" + eventId + "/publish", {
                method: "POST",
                data: {},
              });
            } catch (error) {
              toast(
                "Данные сохранены, но публикация не удалась: " +
                  friendly(error),
                true,
              );
              navigate("/me/events/" + eventId + "/edit");
              return;
            }
          }
          toast(
            publish
              ? "Событие опубликовано"
              : id
                ? "Изменения сохранены"
                : "Черновик сохранён",
          );
          navigate("/me/events");
        } catch (error) {
          formError(form, error);
        } finally {
          busy(form, false);
        }
      };
    },
  };
}
export async function participantsPage(id) {
  const page = new URLSearchParams(location.search).get("page") || 1;
  const [details, list] = await Promise.all([
    api("/events/" + id),
    api(`/events/${id}/participants?page=${page}&per_page=12`),
  ]);
  return {
    title: "Участники события",
    html: cabinet(
      `<a href="/me/events" data-link class="back-link">${icon("back")}Мои мероприятия</a>${pageHeading("Список участников", details.data.title, `Зарегистрировано: ${list.meta.total}`, `<button id="export-button" class="button button-outline">${icon("download")}Экспорт .txt</button>`)}${list.data.length ? `<div class="table-scroll"><table><thead><tr><th>Имя</th><th>Специальность</th><th>Курс</th><th>Время регистрации</th><th>Статус</th></tr></thead><tbody>${list.data.map((row) => `<tr><td><strong>${esc(row.name)}</strong></td><td>${esc(row.faculty)}</td><td>${esc(row.course)}</td><td>${date(row.registered_at)}</td><td><span class="badge ${row.status === "active" ? "badge-green" : "badge-red"}">${row.status === "active" ? "Активна" : "Отменена организатором"}</span></td></tr>`).join("")}</tbody></table></div>` : empty("Участники ещё не записались", "Когда студенты выберут это событие, их имена появятся здесь.")}${pager(list.meta)}`,
    ),
    bind() {
      bindPager(list.meta, (page) =>
        navigate(`/me/events/${id}/participants?page=${page}`),
      );
      document.querySelector("#export-button").onclick = async (e) => {
        const button = e.currentTarget;
        button.disabled = true;
        try {
          const response = await fetch(
            API + `/events/${id}/participants?export=txt`,
            { credentials: "include" },
          );
          if (!response.ok) {
            const result = await response.json();
            throw new ApiError(response.status, result.error);
          }
          const blob = await response.blob();
          const url = URL.createObjectURL(blob);
          const link = document.createElement("a");
          link.href = url;
          link.download = `participants-${id}.txt`;
          link.click();
          setTimeout(() => URL.revokeObjectURL(url), 1000);
          toast("Список участников скачан");
        } catch (error) {
          toast(friendly(error), true);
          if (error.status === 401) navigate("/login");
        } finally {
          button.disabled = false;
        }
      };
    },
  };
}

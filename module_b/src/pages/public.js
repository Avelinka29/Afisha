import { api, queryString, fileUrl } from "../api.js";
import { state } from "../state.js";
import { navigate } from "../router.js";
import {
  esc,
  icon,
  options,
  pageHeading,
  eventCard,
  empty,
  pager,
  bindPager,
  date,
  statusBadge,
  toast,
  friendly,
  ask,
  errorBox,
} from "../ui.js";

export async function homePage() {
  const params = Object.fromEntries(new URLSearchParams(location.search));
  const [list, next] = await Promise.all([
    api("/events?" + queryString({ ...params, per_page: 6 })),
    api("/events?upcoming=true&per_page=1&sort=starts_at"),
  ]);
  const soon = next.data[0];
  const month = new Date().toLocaleDateString("ru-RU", {
    month: "long",
    year: "numeric",
  });
  const html = `<section class="home-hero"><div class="container hero-layout"><div class="hero-copy"><p class="eyebrow"><span class="live-dot"></span>Студенческая жизнь МРК</p><h1>После пар —<br>самое <em>интересное.</em></h1><p class="hero-description">Встречи, открытия и люди рядом.<br>Находи события по душе и будь на связи.</p><a href="#events" class="hero-link">Выбрать своё событие ${icon("arrow")}</a></div><div class="hero-poster"><div class="poster-top"><span>ПЛАНЫ<br>НА БЛИЖАЙШЕЕ</span><span class="poster-calendar">${icon("calendar")}</span></div>${soon ? `<a data-link href="/events/${soon.id}" class="poster-event"><span class="poster-day">${new Date(soon.starts_at).getDate()}<small>${new Date(soon.starts_at).toLocaleDateString("ru-RU", { month: "long" })}</small></span><div><span class="poster-category">${esc(soon.category_name)} · ${soon.format === "online" ? "Онлайн" : "Офлайн"}</span><h2>${esc(soon.title)}</h2><span class="poster-time">${date(soon.starts_at)}</span></div><span class="poster-arrow">${icon("arrow")}</span></a>` : '<p class="poster-placeholder">Новые встречи скоро появятся здесь.</p>'}<div class="poster-bottom"><span>МРК / СООБЩЕСТВА</span><span>${esc(month)}</span></div></div></div></section>
  <section id="events" class="container events-section"><div class="section-heading"><div><p class="eyebrow">Есть повод встретиться</p><h2>Афиша событий<span class="count-label">${list.meta.total}</span></h2></div><span class="small-note">Твои планы начинаются здесь</span></div>
  <div class="category-tabs" role="group" aria-label="Категории мероприятий"><button data-category="" class="${!params.category_id ? "active" : ""}">Все события</button>${state.categories.map((cat) => `<button data-category="${cat.id}" class="${String(cat.id) === params.category_id ? "active" : ""}">${esc(cat.name)}</button>`).join("")}</div>
  <div class="events-layout"><aside class="filters-panel"><div class="filter-title">${icon("filter")}Фильтры<a data-link href="/#events">Сбросить</a></div><form id="filters-form"><label class="field">Сообщество<select name="club_id">${options(state.clubs, params.club_id, "Все сообщества")}</select></label><label class="field">Формат<select name="format"><option value="">Любой формат</option><option value="offline" ${params.format === "offline" ? "selected" : ""}>Офлайн</option><option value="online" ${params.format === "online" ? "selected" : ""}>Онлайн</option></select></label><fieldset class="date-range"><legend>Когда встречаемся</legend><label class="field">С<input type="date" name="date_from" value="${esc(params.date_from)}" /></label><label class="field">По<input type="date" name="date_to" value="${esc(params.date_to)}" /></label></fieldset><label class="checkbox-label"><input name="upcoming" type="checkbox" ${params.upcoming === "true" ? "checked" : ""} />Только предстоящие</label><button class="button button-full" type="submit">Применить ${icon("arrow")}</button></form><div class="sidebar-note"><span class="note-mark">${icon("people")}</span><p>Своё сообщество —<br>это ближе, чем кажется.</p><a href="/communities" data-link>Познакомиться ${icon("arrow")}</a></div></aside>
  <div class="events-results"><div class="results-toolbar"><form class="search-form" id="search-form"><label class="sr-only" for="event-search">Поиск по названию и описанию</label>${icon("search")}<input id="event-search" name="q" type="search" placeholder="Поиск по событиям" value="${esc(params.q)}" maxlength="200" /><button type="submit" aria-label="Найти">${icon("arrow")}</button></form><label class="sort-field"><span class="sr-only">Сортировка</span><select id="sort"><option value="starts_at" ${params.sort !== "popularity" ? "selected" : ""}>По дате</option><option value="popularity" ${params.sort === "popularity" ? "selected" : ""}>По популярности</option></select></label></div><p id="search-status" class="search-status" role="status" aria-live="polite"></p><div id="event-list-results">${list.data.length ? `<div class="events-grid">${list.data.map((event) => eventCard(event, params.q || "")).join("")}</div>` : empty("Ничего не нашлось", "Попробуй другую дату, сообщество или поисковый запрос.", '<a class="button button-outline" data-link href="/#events">Сбросить фильтры</a>')}${pager(list.meta)}</div></div></div></section>`;
  const update = (changes) =>
    navigate(
      "/?" +
        queryString({ ...params, ...changes, page: changes.page || 1 }) +
        "#events",
    );
  return {
    html,
    title: "Афиша",
    bind() {
      document
        .querySelectorAll("[data-category]")
        .forEach(
          (button) =>
            (button.onclick = () =>
              update({ category_id: button.dataset.category })),
        );
      document.querySelector("#filters-form").onsubmit = (e) => {
        e.preventDefault();
        const data = Object.fromEntries(new FormData(e.target));
        update({ ...data, upcoming: e.target.upcoming.checked ? "true" : "" });
      };
      let timer = null,
        controller = null,
        version = 0;
      const search = document.querySelector("#event-search");
      const results = document.querySelector("#event-list-results");
      const status = document.querySelector("#search-status");
      const perform = async (id) => {
        controller = new AbortController();
        params.q = search.value.trim();
        params.page = 1;
        history.replaceState({}, "", "/?" + queryString(params) + "#events");
        results.setAttribute("aria-busy", "true");
        status.textContent = "Ищем события…";
        try {
          const data = await api(
            "/events?" + queryString({ ...params, per_page: 6 }),
            { signal: controller.signal },
          );
          if (id !== version || !results.isConnected) return;
          results.innerHTML =
            (data.data.length
              ? '<div class="events-grid">' +
                data.data.map((event) => eventCard(event, params.q)).join("") +
                "</div>"
              : empty(
                  "Ничего не нашлось",
                  "Попробуй другую дату, сообщество или поисковую фразу.",
                )) + pager(data.meta);
          document.querySelector(".count-label").textContent = data.meta.total;
          status.textContent = params.q
            ? "Найдено: " + data.meta.total + ". Поиск по названию и описанию."
            : "";
          bindPager(data.meta, (page) => update({ page }));
        } catch (error) {
          if (error.name === "AbortError" || id !== version) return;
          results.innerHTML = errorBox(error);
          status.textContent = "";
        } finally {
          if (id === version) results.removeAttribute("aria-busy");
        }
      };
      // Отменяем старый запрос, чтобы результаты предыдущей буквы не заменили новые.
      search.oninput = () => {
        clearTimeout(timer);
        controller?.abort();
        const id = ++version;
        status.textContent = "Ищем события…";
        params.q = search.value.trim();
        timer = setTimeout(() => perform(id), 250);
      };
      document.querySelector("#search-form").onsubmit = (e) => {
        e.preventDefault();
        clearTimeout(timer);
        controller?.abort();
        perform(++version);
      };
      document.querySelector("#sort").onchange = (e) =>
        update({ sort: e.target.value });
      bindPager(list.meta, (page) => update({ page }));
      return () => {
        clearTimeout(timer);
        controller?.abort();
      };
    },
  };
}

export async function detailPage(id) {
  const { data: event } = await api("/events/" + id);
  const started = new Date(event.starts_at).getTime() <= Date.now();
  let registrationLabel = event.is_registered
    ? "Отменить регистрацию"
    : "Пойду";
  if (event.status === "cancelled") registrationLabel = "Мероприятие отменено";
  else if (started)
    registrationLabel =
      event.display_status === "ongoing"
        ? "Мероприятие уже идёт"
        : "Мероприятие завершено";
  else if (!event.is_registered && event.free_places === 0)
    registrationLabel = "Все места заняты";
  const disabled =
    started ||
    event.status !== "published" ||
    (!event.is_registered && event.free_places === 0);
  const url = fileUrl(event.cover_url);
  const html = `<div class="container detail-page"><a href="/#events" data-link class="back-link">${icon("back")}К афише</a><div class="detail-layout"><article class="detail-content"><div class="detail-cover cover-club-${event.club_id}">${url ? `<img src="${esc(url)}" alt="Обложка мероприятия ${esc(event.title)}" />` : ""}<div class="detail-cover-label"><span class="badge badge-paper">${esc(event.category_name)}</span>${statusBadge(event)}</div></div><div class="detail-heading"><a href="/?club_id=${event.club_id}" data-link class="club-label"><span class="club-dot"></span>${esc(event.club_name)}</a><h1>${esc(event.title)}</h1></div>${event.status === "cancelled" ? `<div class="notice notice-error"><strong>Мероприятие отменено</strong><p>${esc(event.cancellation_reason)}</p></div>` : ""}<section class="event-description"><h2>О событии</h2><p>${esc(event.description).replace(/\n/g, "<br>")}</p></section><div class="event-organizer"><span class="organizer-symbol">${icon("people")}</span><div><small>Организатор</small><h3>${esc(event.club_name)}</h3></div><a href="/?club_id=${event.club_id}" data-link class="text-link">Все события ${icon("arrow")}</a></div>${event.can_manage ? `<a href="/me/events/${event.id}/edit" data-link class="button button-outline">${icon("edit")}Управление мероприятием</a>` : ""}</article>
  <aside class="event-booking"><p class="eyebrow">Увидимся на событии</p><div class="booking-item">${icon("calendar")}<div><small>Начало</small><strong>${date(event.starts_at)}</strong></div></div><div class="booking-item">${icon("clock")}<div><small>Окончание</small><strong>${date(event.ends_at)}</strong></div></div><div class="booking-item">${icon(event.format === "online" ? "globe" : "pin")}<div><small>${event.format === "online" ? "Онлайн-встреча" : "Место встречи"}</small>${event.format === "online" ? `<a href="${esc(fileUrl(event.location))}" target="_blank" rel="noopener noreferrer" class="text-link">Открыть ссылку ${icon("arrow")}</a>` : `<strong>${esc(event.location)}</strong>`}</div></div><div class="booking-capacity"><div class="capacity-caption"><span>${icon("people")}${event.registered_count} участников</span><strong>${event.capacity === null ? "Без лимита" : event.free_places + " мест осталось"}</strong></div>${event.capacity ? `<div class="progress"><span style="width:${Math.min(100, (100 * event.registered_count) / event.capacity)}%"></span></div>` : ""}</div>${event.is_registered ? '<p class="registered-banner">' + icon("check") + "Ты в списке участников</p>" : ""}<button class="button button-full ${event.is_registered ? "button-outline" : ""}" id="register-button" ${disabled ? "disabled" : ""}>${icon(event.is_registered ? "close" : "plus")}${registrationLabel}</button>${disabled ? `<p class="booking-state" role="status">${event.status === "cancelled" ? "Организатор отменил событие. Регистрация недоступна." : started ? "После начала нельзя записаться или отменить регистрацию." : event.status === "draft" ? "Это черновик. Запись откроется после публикации." : "Свободных мест нет. Регистрация закрыта."}</p>` : ""}${!state.user && !disabled ? '<p class="booking-hint">Для записи понадобится войти в аккаунт</p>' : ""}<div class="reactions"><button id="like-button" class="reaction-button ${event.my_reactions.includes("like") ? "selected" : ""}" aria-pressed="${event.my_reactions.includes("like")}">${icon("heart")}<span>Нравится</span><b>${event.likes_count}</b></button><button id="interested-button" class="reaction-button ${event.my_reactions.includes("interested") ? "selected" : ""}" aria-pressed="${event.my_reactions.includes("interested")}">${icon("star")}<span>Интересно</span><b>${event.interested_count}</b></button></div><p class="booking-hint">Отмечай интересное — события останутся<br>в твоём личном кабинете.</p></aside></div></div>`;
  const refresh = () =>
    window.dispatchEvent(
      new CustomEvent("route-change", { detail: { keepScroll: true } }),
    );
  const requireLogin = () => {
    if (state.user) return true;
    navigate("/login?next=" + encodeURIComponent("/events/" + id));
    return false;
  };
  return {
    html,
    title: event.title,
    bind() {
      // Это удобство интерфейса. Сервер независимо проверяет время при POST и DELETE.
      const updateStarted = () => {
        if (
          event.status === "published" &&
          Date.now() >= new Date(event.starts_at).getTime()
        ) {
          const button = document.querySelector("#register-button");
          if (!button) return;
          button.disabled = true;
          button.textContent =
            Date.now() >= new Date(event.ends_at).getTime()
              ? "Мероприятие завершено"
              : "Регистрация закрыта: событие началось";
          if (!document.querySelector(".booking-state")) {
            const message = document.createElement("p");
            message.className = "booking-state";
            message.setAttribute("role", "status");
            message.textContent =
              "После начала нельзя записаться или отменить регистрацию.";
            button.after(message);
          }
        }
      };
      const timer = setInterval(updateStarted, 1000);
      updateStarted();
      document.querySelector("#register-button").onclick = async (e) => {
        if (!requireLogin()) return;
        if (
          event.is_registered &&
          !(await ask({
            title: "Отменить свою регистрацию?",
            text: "Освободившееся место сможет занять другой студент.",
            confirm: "Отменить регистрацию",
            danger: true,
          }))
        )
          return;
        e.currentTarget.disabled = true;
        try {
          await api(`/events/${id}/register`, {
            method: event.is_registered ? "DELETE" : "POST",
            data: {},
          });
          toast(
            event.is_registered
              ? "Регистрация отменена"
              : "Отлично, ты в списке участников!",
          );
          refresh();
        } catch (error) {
          toast(friendly(error), true);
          refresh();
        }
      };
      for (const type of ["like", "interested"])
        document.querySelector("#" + type + "-button").onclick = async (e) => {
          if (!requireLogin()) return;
          const button = e.currentTarget;
          button.disabled = true;
          const active = event.my_reactions.includes(type);
          try {
            await api(
              `/events/${id}/reactions${active ? "?type=" + type : ""}`,
              {
                method: active ? "DELETE" : "POST",
                data: active ? undefined : { type },
              },
            );
            refresh();
          } catch (error) {
            toast(friendly(error), true);
            button.disabled = false;
          }
        };
      return () => clearInterval(timer);
    },
  };
}
export async function communitiesPage() {
  const { data: clubs } = await api("/clubs");
  return {
    title: "Сообщества",
    html: `<div class="container standalone-page">${pageHeading("Люди, с которыми по пути", "Найди своё сообщество", "Спорт, наука, творчество и добрые дела. Вместе всегда интереснее.")}<div class="communities-grid">${clubs.map((club, i) => `<article class="community-card"><div class="community-number">0${i + 1}<span>${icon("people")}</span></div><h2>${esc(club.name)}</h2><p>${esc(club.description)}</p><a href="/?club_id=${club.id}#events" data-link class="text-link">Посмотреть события ${icon("arrow")}</a></article>`).join("")}</div></div>`,
  };
}

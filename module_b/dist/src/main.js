import { api, ApiError } from "./api.js";
import { state, isRepresentative, isAdmin } from "./state.js";
import { initRouter, navigate } from "./router.js";
import {
  header,
  footer,
  loading,
  errorBox,
  cabinet,
  toast,
  friendly,
  ask,
} from "./ui.js";
import { homePage, detailPage, communitiesPage } from "./pages/public.js";
import {
  loginPage,
  registerPage,
  resetPage,
  verifyPage,
} from "./pages/auth.js";
import {
  profilePage,
  registrationsPage,
  reactionsPage,
  notificationsPage,
} from "./pages/account.js";
import { managePage, eventFormPage, participantsPage } from "./pages/manage.js";
import { adminPage } from "./pages/admin.js";
import { googleFeedback } from "./google.js";
import { googleCompletePage } from "./pages/auth.js";
const app = document.querySelector("#app");
let renderId = 0;
let pageCleanup = null;
function shell(content) {
  app.innerHTML =
    header() + `<main id="main" tabindex="-1">${content}</main>` + footer();
}
async function refreshUser() {
  try {
    state.user = (await api("/me", { allow401: true })).data;
  } catch (error) {
    if (error.status === 401) state.user = null;
    else throw error;
  }
  if (!state.user || state.user.blocked) state.unread = 0;
}
async function getPage() {
  const path = location.pathname.replace(/\/$/, "") || "/";
  let match;
  if (path.startsWith("/me") || path === "/admin") {
    await refreshUser();
    if (!state.user) {
      navigate(
        "/login?next=" + encodeURIComponent(path + location.search),
        true,
      );
      return null;
    }
    if (state.user.blocked && path !== "/me")
      throw new ApiError(403, { code: "USER_BLOCKED" });
    if (path.startsWith("/me/events") && !isRepresentative())
      throw new ApiError(403, { code: "FORBIDDEN" });
    if (path === "/admin" && !isAdmin())
      throw new ApiError(403, { code: "FORBIDDEN" });
  }
  if (path === "/") return homePage();
  if (path === "/communities") return communitiesPage();
  if ((match = path.match(/^\/events\/(\d+)$/))) return detailPage(match[1]);
  if (path === "/login") return loginPage();
  if (path === "/register") return registerPage();
  if (path === "/password-reset") return resetPage();
  if (path === "/verify-email") return verifyPage();
  if (path === "/google-complete") return googleCompletePage();
  if (path === "/me") return profilePage();
  if (path === "/me/registrations") return registrationsPage();
  if (path === "/me/reactions") return reactionsPage();
  if (path === "/me/notifications") return notificationsPage();
  if (path === "/me/events") return managePage();
  if (path === "/me/events/new") return eventFormPage();
  if ((match = path.match(/^\/me\/events\/(\d+)\/edit$/)))
    return eventFormPage(match[1]);
  if ((match = path.match(/^\/me\/events\/(\d+)\/participants$/)))
    return participantsPage(match[1]);
  if (path === "/admin") return adminPage();
  throw new ApiError(404, { code: "NOT_FOUND" });
}
// Перед переходом останавливаем поиск и таймеры предыдущей страницы.
async function render(event) {
  pageCleanup?.();
  pageCleanup = null;
  googleFeedback();
  const current = ++renderId;
  const scroll = window.scrollY;
  const keepScroll = event?.detail?.keepScroll;
  shell(loading());
  try {
    if (!state.clubs.length || !state.categories.length) {
      const [clubs, categories] = await Promise.all([
        api("/clubs"),
        api("/categories"),
      ]);
      state.clubs = clubs.data;
      state.categories = categories.data;
    }
    const page = await getPage();
    // Медленный ответ от предыдущего перехода не должен заменить новую страницу.
    if (current !== renderId || !page) return;
    if (state.user && !state.user.blocked) {
      const result = await api("/me/notifications?per_page=1");
      state.unread = result.meta.unread_total;
    }
    if (current !== renderId) return;
    shell(page.html);
    document.title = page.title + " · На связи — МРК";
    pageCleanup = page.bind?.() || null;
    if (keepScroll) window.scrollTo(0, scroll);
    else if (location.hash) {
      document
        .getElementById(location.hash.slice(1))
        ?.scrollIntoView({ behavior: "instant" });
    } else window.scrollTo(0, 0);
  } catch (error) {
    if (current !== renderId) return;
    const content = errorBox(error);
    shell(
      state.user && location.pathname.startsWith("/me")
        ? cabinet(content)
        : `<div class="container standalone-page">${content}<a href="/" data-link class="button button-outline">К афише</a></div>`,
    );
  }
}
initRouter();
window.addEventListener("route-change", render);
window.addEventListener("session-expired", () => {
  state.user = null;
  state.unread = 0;
  if (location.pathname !== "/login") {
    toast("Сессия закончилась. Войди снова.", true);
    navigate(
      "/login?next=" + encodeURIComponent(location.pathname + location.search),
      true,
    );
  }
});
document.addEventListener("click", async (event) => {
  const retry = event.target.closest("[data-retry]");
  if (retry) {
    render();
    return;
  }
  const logout = event.target.closest("[data-logout]");
  if (!logout) return;
  if (
    !(await ask({
      title: "Выйти из аккаунта?",
      text: "Ты сможешь войти снова в любое время.",
      confirm: "Выйти",
    }))
  )
    return;
  logout.disabled = true;
  try {
    await api("/auth/logout", { method: "POST", data: {} });
    state.user = null;
    state.unread = 0;
    toast("Ты вышла из аккаунта");
    navigate("/");
  } catch (error) {
    toast(friendly(error), true);
    logout.disabled = false;
  }
});
document.addEventListener(
  "error",
  (event) => {
    if (event.target instanceof HTMLImageElement) {
      event.target.hidden = true;
      event.target.parentElement.classList.add("image-unavailable");
    }
  },
  true,
);
(async () => {
  shell(loading());
  try {
    await refreshUser();
  } catch (error) {
    toast(friendly(error), true);
  }
  await render();
})();

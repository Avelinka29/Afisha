import { api } from "./api.js";
import { toast, friendly } from "./ui.js";
export const googleButton = (mode = "login") =>
  `<button type="button" class="button button-outline google-button" data-google="${mode}"><span class="google-letter" aria-hidden="true">G</span>${mode === "link" ? "Привязать Google" : "Продолжить с Google"}</button>`;
export function bindGoogle() {
  document.querySelectorAll("[data-google]").forEach(
    (button) =>
      (button.onclick = async () => {
        button.disabled = true;
        try {
          const params = new URLSearchParams({
            mode: button.dataset.google,
            next: new URLSearchParams(location.search).get("next") || "/",
          });
          const result = await api("/auth/google/url?" + params, {
            allow401: button.dataset.google !== "link",
          });
          window.location.assign(result.data.url);
        } catch (error) {
          toast(friendly(error), true);
          button.disabled = false;
        }
      }),
  );
}
export function googleFeedback() {
  const params = new URLSearchParams(location.search);
  const code = params.get("google_error");
  const messages = {
    GOOGLE_INVALID_STATE:
      "Попытка входа устарела. Нажми «Продолжить с Google» ещё раз.",
    GOOGLE_CANCELLED: "Вход через Google отменён.",
    GOOGLE_UNAVAILABLE:
      "Не удалось связаться с Google. Попробуй ещё раз или войди по паролю.",
    GOOGLE_INVALID_TOKEN: "Не удалось подтвердить ответ Google. Повтори вход.",
    GOOGLE_ACCOUNT_EXISTS:
      "Этот email уже зарегистрирован. Войди по паролю и нажми «Привязать Google» в профиле.",
    GOOGLE_ACCOUNT_LINKED: "Этот Google уже привязан к другому профилю.",
    USER_BLOCKED: "Аккаунт заблокирован. Обратись к администратору.",
    NOT_VERIFIED: "Сначала подтверди email своего профиля.",
    GMAIL_WRONG_ACCOUNT:
      "Выбери Google-аккаунт отправителя, указанный на странице «Почта».",
    GMAIL_PERMISSION_REQUIRED:
      "Нужно разрешить отправку писем и включить Gmail API в проекте Afisha.",
    GMAIL_RECONNECT_REQUIRED:
      "Google не выдал постоянный доступ. Подключи почту заново.",
    MAIL_UNAVAILABLE:
      "Gmail пока не принял письмо. Проверь подключение почты и повтори отправку.",
  };
  if (code) {
    toast(messages[code] || "Не удалось завершить вход через Google.", true);
    params.delete("google_error");
  }
  if (params.has("google_linked")) {
    toast("Google привязан к твоему профилю");
    params.delete("google_linked");
  }
  const mailConnected = params.has("mail_connected");
  if (mailConnected) {
    toast("Gmail подключён и принял настоящее тестовое письмо");
    params.delete("mail_connected");
  }
  if (
    code ||
    mailConnected ||
    new URLSearchParams(location.search).has("google_linked")
  )
    history.replaceState(
      {},
      "",
      location.pathname + (params.size ? "?" + params : "") + location.hash,
    );
}

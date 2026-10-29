import { googleButton, bindGoogle } from "../google.js";
import { api, ApiError } from "../api.js";
import { state } from "../state.js";
import { navigate } from "../router.js";
import { esc, icon, formAlert, formError, busy, toast } from "../ui.js";
const nextPath = () => {
  const path = new URLSearchParams(location.search).get("next");
  return path?.startsWith("/") && !path.startsWith("//") ? path : "/";
};
function authLayout(content) {
  return `<div class="container auth-page"><aside class="auth-story"><p class="eyebrow">На связи · МРК</p><h1>Свои люди.<br>Общие планы.<br><em>Твои истории.</em></h1><p>Присоединяйся к студенческой жизни:<br>от первой встречи до своего мероприятия.</p><div class="auth-decoration" aria-hidden="true"><span>ВСТРЕЧАЕМСЯ</span><b>после<br>пар.</b><i>↗</i></div></aside><section class="auth-form-panel">${content}</section></div>`;
}
const passwordField = (
  label = "Пароль",
  name = "password",
  autocomplete = "current-password",
) =>
  `<div class="field"><label for="auth-${name}">${label}</label><span class="password-input"><input id="auth-${name}" type="password" name="${name}" required minlength="8" maxlength="72" autocomplete="${autocomplete}" /><button type="button" data-toggle-password aria-label="Показать пароль">Показать</button></span></div>`;
export function bindPasswords(root = document) {
  root.querySelectorAll("[data-toggle-password]").forEach(
    (button) =>
      (button.onclick = () => {
        const input = button.parentElement.querySelector("input");
        const show = input.type === "password";
        input.type = show ? "text" : "password";
        button.textContent = show ? "Скрыть" : "Показать";
        button.setAttribute(
          "aria-label",
          show ? "Скрыть пароль" : "Показать пароль",
        );
      }),
  );
}
export async function loginPage() {
  if (state.user && !state.user.blocked) {
    navigate(nextPath(), true);
    return null;
  }
  return {
    title: "Вход",
    html: authLayout(
      `<a href="/" data-link class="back-link">${icon("back")}К афише</a><p class="eyebrow">Рады снова видеть</p><h2>С возвращением</h2><p class="subtitle">Войди, чтобы не пропускать интересное.</p>${googleButton()}<div class="auth-divider">или по email</div><form id="login-form">${formAlert}<label class="field">Email<input type="email" name="email" required maxlength="190" autocomplete="email" placeholder="you@example.com" /></label>${passwordField()}<a href="/password-reset" data-link class="forgot-link">Забыли пароль?</a><button type="submit" class="button button-full">Войти ${icon("arrow")}</button></form><p class="auth-bottom">Ещё нет аккаунта? <a href="/register" data-link>Зарегистрироваться</a></p>`,
    ),
    bind() {
      bindPasswords();
      bindGoogle();
      const form = document.querySelector("#login-form");
      form.onsubmit = async (e) => {
        e.preventDefault();
        busy(form, true);
        try {
          const result = await api("/auth/login", {
            method: "POST",
            data: Object.fromEntries(new FormData(form)),
            allow401: true,
          });
          state.user = result.data;
          toast("С возвращением!");
          navigate(nextPath(), true);
        } catch (error) {
          formError(form, error);
          if (
            error.code === "NOT_VERIFIED" &&
            !form.querySelector("[data-resend]")
          ) {
            const button = document.createElement("button");
            button.type = "button";
            button.dataset.resend = "";
            button.className = "text-button";
            button.textContent = "Отправить письмо подтверждения ещё раз";
            form
              .querySelector("[data-form-error]")
              .append(document.createElement("br"), button);
            button.onclick = async () => {
              button.disabled = true;
              try {
                await api("/auth/resend-verification", {
                  method: "POST",
                  data: { email: form.email.value },
                  allow401: true,
                });
                toast(
                  "Запрос принят. Для неподтверждённой почты подготовлено новое письмо",
                );
              } catch (error) {
                toast(error.message, true);
              } finally {
                button.disabled = false;
              }
            };
          }
        } finally {
          busy(form, false);
        }
      };
    },
  };
}
export async function registerPage() {
  return {
    title: "Регистрация",
    html: authLayout(
      `<p class="eyebrow">Стань частью сообщества</p><h2>Будем на связи</h2><p class="subtitle">Немного о себе — и можно строить планы.</p>${googleButton()}<div class="auth-divider">или по email</div><form id="register-form">${formAlert}<label class="field">Имя<input name="name" required maxlength="120" autocomplete="name" placeholder="Как к тебе обращаться?" /></label><label class="field">Email<input type="email" name="email" required maxlength="190" autocomplete="email" placeholder="you@example.com" /></label><div class="form-row"><label class="field">Специальность<input name="faculty" required maxlength="190" placeholder="Твоя специальность" /></label><label class="field course-field">Курс<select name="course" required><option value="">Выбрать</option>${Array.from({ length: 4 }, (_, i) => `<option value="${i + 1}">${i + 1} курс</option>`).join("")}</select></label></div>${passwordField("Пароль", "password", "new-password")}<p class="field-hint">Не менее 8 символов</p><button type="submit" class="button button-full">Создать аккаунт ${icon("arrow")}</button></form><p class="auth-bottom">Уже с нами? <a href="/login" data-link>Войти</a></p>`,
    ),
    bind() {
      bindPasswords();
      bindGoogle();
      const form = document.querySelector("#register-form");
      form.onsubmit = async (e) => {
        e.preventDefault();
        busy(form, true);
        try {
          const data = Object.fromEntries(new FormData(form));
          data.course = Number(data.course);
          const result = await api("/auth/register", {
            method: "POST",
            data,
            allow401: true,
          });
          document.querySelector(".auth-form-panel").innerHTML =
            `<span class="success-mark">${icon("check")}</span><p class="eyebrow">Остался один шаг</p><h2>Проверь свою почту</h2><p class="subtitle">${result.mail_mode === "log" ? "Аккаунт создан, но письмо подтверждения не отправлено. Обратитесь к администратору для подключения почты." : `Отправили ссылку на <strong>${esc(data.email)}</strong>.`} Подтверди email — после этого ты автоматически войдёшь в аккаунт.</p><a class="button" href="/login" data-link>Перейти ко входу ${icon("arrow")}</a>`;
        } catch (error) {
          formError(form, error);
        } finally {
          busy(form, false);
        }
      };
    },
  };
}
export async function resetPage() {
  const token = new URLSearchParams(location.search).get("token");
  const resetForm = () =>
    `<p class="eyebrow">Новый старт</p><h2>Новый пароль</h2><p class="subtitle">Придумай пароль для своего аккаунта.</p><form id="reset-form">${formAlert}${token ? `<input type="hidden" name="token" value="${esc(token)}" />` : '<label class="field">Код из письма<input name="token" required autocomplete="off" /></label>'}${passwordField("Новый пароль", "password", "new-password")}${passwordField("Повтори пароль", "password_confirmation", "new-password")}<button type="submit" class="button button-full">Сохранить пароль ${icon("arrow")}</button></form>`;
  return {
    title: "Восстановление пароля",
    html: authLayout(
      `<a href="/login" data-link class="back-link">${icon("back")}Ко входу</a><div id="reset-content">${token ? resetForm() : `<p class="eyebrow">Вернём доступ</p><h2>Забыли пароль?</h2><p class="subtitle">Укажи email, с которым регистрировалась.</p><form id="forgot-form">${formAlert}<label class="field">Email<input type="email" name="email" required maxlength="190" autocomplete="email" placeholder="you@example.com" /></label><button type="submit" class="button button-full">Получить письмо ${icon("arrow")}</button></form><button class="text-button" id="have-token">У меня уже есть код из письма</button>`}</div>`,
    ),
    bind() {
      const bindReset = () => {
        bindPasswords();
        const form = document.querySelector("#reset-form");
        form.onsubmit = async (e) => {
          e.preventDefault();
          busy(form, true);
          try {
            const data = Object.fromEntries(new FormData(form));
            if (data.password !== data.password_confirmation)
              throw new ApiError(422, {
                message: "Пароли не совпадают",
                fields: {
                  password_confirmation: ["Повтори новый пароль без изменений"],
                },
              });
            await api("/auth/password/reset", {
              method: "POST",
              data,
              allow401: true,
            });
            state.user = null;
            toast("Пароль изменён. Теперь можно войти.");
            navigate("/login");
          } catch (error) {
            formError(form, error);
          } finally {
            busy(form, false);
          }
        };
      };
      if (token) bindReset();
      else {
        document.querySelector("#have-token").onclick = () => {
          document.querySelector("#reset-content").innerHTML = resetForm();
          bindReset();
        };
        const form = document.querySelector("#forgot-form");
        form.onsubmit = async (e) => {
          e.preventDefault();
          busy(form, true);
          try {
            await api("/auth/password/forgot", {
              method: "POST",
              data: Object.fromEntries(new FormData(form)),
              allow401: true,
            });
            toast(
              "Если аккаунт с такой почтой есть, письмо восстановления подготовлено.",
            );
            form.querySelector("button").textContent = "Отправить ещё раз";
          } catch (error) {
            formError(form, error);
          } finally {
            busy(form, false);
          }
        };
      }
    },
  };
}
export async function verifyPage() {
  const token = new URLSearchParams(location.search).get("token") || "";
  const result = await api("/auth/verify-email", {
    method: "POST",
    data: { token },
    allow401: true,
  });
  state.user = result.data;
  return {
    title: "Почта подтверждена",
    html: authLayout(
      '<span class="success-mark">' +
        icon("check") +
        '</span><h2>Ты на связи!</h2><p class="subtitle">Почта подтверждена. Перенаправляем на афишу…</p>',
    ),
    bind() {
      setTimeout(() => navigate("/", true), 700);
    },
  };
}

// Google подтверждает email, а курс и специальность заполняет сам студент.
export async function googleCompletePage() {
  const { data: pending } = await api("/auth/google/pending", {
    allow401: true,
  });
  return {
    title: "Завершение регистрации",
    html: authLayout(
      `<p class="eyebrow">Google подтвердил почту</p><h2>Давай познакомимся</h2><p class="subtitle">Осталось указать специальность и курс. Пароль пригодится для входа без Google.</p><form id="google-complete-form">${formAlert}<label class="field">Email<input type="email" value="${esc(pending.email)}" disabled /></label><label class="field">Имя<input name="name" required maxlength="120" value="${esc(pending.name)}" autocomplete="name" /></label><label class="field">Специальность<input name="faculty" required maxlength="190" /></label><label class="field">Курс<select name="course" required><option value="">Выбрать</option>${[1, 2, 3, 4].map((course) => `<option value="${course}">${course} курс</option>`).join("")}</select></label>${passwordField("Пароль для входа без Google", "password", "new-password")}<button type="submit" class="button button-full">Завершить регистрацию ${icon("arrow")}</button></form>`,
    ),
    bind() {
      bindPasswords();
      const form = document.querySelector("#google-complete-form");
      form.onsubmit = async (e) => {
        e.preventDefault();
        busy(form, true);
        try {
          const data = Object.fromEntries(new FormData(form));
          data.course = Number(data.course);
          const result = await api("/auth/google/complete", {
            method: "POST",
            data,
            allow401: true,
          });
          state.user = result.data;
          toast("Добро пожаловать!");
          navigate(result.next || "/", true);
        } catch (error) {
          formError(form, error);
        } finally {
          busy(form, false);
        }
      };
    },
  };
}

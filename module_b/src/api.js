// Сервер хранит данные и проверяет права. Здесь только отправка запросов.
export const API = "http://localhost:8000/api";
export const SERVER = "http://localhost:8000";
export class ApiError extends Error {
  constructor(status, error) {
    super(error?.message || "Не удалось выполнить запрос");
    this.status = status;
    this.code = error?.code;
    this.fields = error?.fields || {};
  }
}
export async function api(
  path,
  { method = "GET", data, allow401 = false, signal } = {},
) {
  let response;
  try {
    response = await fetch(API + path, {
      method,
      credentials: "include",
      signal,
      headers:
        data instanceof FormData ? {} : { "Content-Type": "application/json" },
      body:
        data === undefined
          ? undefined
          : data instanceof FormData
            ? data
            : JSON.stringify(data),
    });
  } catch (error) {
    if (error.name === "AbortError") throw error;
    throw new ApiError(0, {
      code: "NETWORK_ERROR",
      message:
        "Нет связи с сервером. Проверьте, что PHP API запущен на порту 8000.",
    });
  }
  let result;
  try {
    result = await response.json();
  } catch {
    throw new ApiError(response.status, {
      message: "Сервер вернул некорректный ответ",
    });
  }
  if (!response.ok) {
    if (response.status === 401 && !allow401)
      window.dispatchEvent(new Event("session-expired"));
    throw new ApiError(response.status, result.error);
  }
  return result;
}
export function queryString(values) {
  return new URLSearchParams(
    Object.entries(values).filter(
      ([, value]) => value !== "" && value !== null && value !== undefined,
    ),
  ).toString();
}
export function fileUrl(value) {
  if (!value) return "";
  if (value.startsWith("/uploads/")) return SERVER + value;
  try {
    const url = new URL(value);
    return ["http:", "https:"].includes(url.protocol) ? url.href : "";
  } catch {
    return "";
  }
}
export async function upload(file) {
  if (!file || !file.size) return null;
  if (
    !["image/jpeg", "image/png", "image/webp"].includes(file.type) ||
    file.size > 4 * 1024 * 1024
  ) {
    throw new ApiError(422, {
      message: "Выберите JPEG, PNG или WebP размером до 4 МБ",
      fields: { file: ["Недопустимый файл"] },
    });
  }
  const data = new FormData();
  data.append("file", file);
  return (await api("/uploads", { method: "POST", data })).data.url;
}

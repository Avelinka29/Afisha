// Меняем адрес и содержимое страницы, сохраняя сессию и работу без перезагрузки.
export function navigate(path, replace = false) {
  history[replace ? "replaceState" : "pushState"]({}, "", path);
  window.dispatchEvent(new Event("route-change"));
}
export function initRouter() {
  window.addEventListener("popstate", () =>
    window.dispatchEvent(new Event("route-change")),
  );
  document.addEventListener("click", (event) => {
    const link = event.target.closest("a[data-link]");
    if (
      !link ||
      event.ctrlKey ||
      event.metaKey ||
      event.shiftKey ||
      event.altKey ||
      event.button !== 0
    )
      return;
    event.preventDefault();
    navigate(link.getAttribute("href"));
  });
}

export const state = {
  user: null,
  clubs: [],
  categories: [],
  unread: 0,
  loadError: null,
};
// Права представителя определяются подтверждёнными связями с сообществами.
export const isRepresentative = () =>
  Boolean(state.user?.clubs?.some((club) => club.verified));
export const isAdmin = () => state.user?.role === "admin";

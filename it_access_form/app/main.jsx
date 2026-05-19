// Main entry — mounts the app.

// Global React hook aliases used by all JSX files that lack their own destructure.
const { useState, useEffect, useCallback, useRef, useMemo } = React;

function App() {
  return (
    <AppProvider initialRole={window.__REACT_INITIAL_ROLE__ || "manager"}>
      <DeepLinkHandler/>
      <div className="app-shell">
        <TopBar/>
        <RouteView/>
      </div>
    </AppProvider>
  );
}

// Navigates to a specific request after the request list has loaded.
// Reads window.__DEEP_LINK__ = { role: "director"|"officer", refNumber: "REQ-…" }
function DeepLinkHandler() {
  const { state, dispatch } = useApp();
  const handled = useRef(false);

  useEffect(() => {
    const link = window.__DEEP_LINK__;
    if (!link || handled.current) return;
    // Wait until real requests have loaded (seed data has no db_id)
    const req = state.requests.find(r => r.id === link.refNumber && r.db_id);
    if (!req) return;
    handled.current = true;
    if (link.role === "director") {
      dispatch({ type: "set-route", route: { name: "director-sign" }, params: { requestId: req.id } });
    } else if (link.role === "officer") {
      dispatch({ type: "set-route", route: { name: "officer-sign" }, params: { requestId: req.id } });
    }
  }, [state.requests]);

  return null;
}

window.App = App;
ReactDOM.createRoot(document.getElementById("root")).render(<App/>);

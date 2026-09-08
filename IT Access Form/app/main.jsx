// Main entry - mounts the app.

// Global React hook aliases used by all JSX files that lack their own destructure.
const { useState, useEffect, useCallback, useRef, useMemo } = React;

function App() {
  return (
    <AppProvider initialRole={window.__REACT_INITIAL_ROLE__ || "manager"}>
      <div className="app-shell">
        <TopBar/>
        <RouteView/>
      </div>
    </AppProvider>
  );
}

window.App = App;
ReactDOM.createRoot(document.getElementById("root")).render(<App/>);

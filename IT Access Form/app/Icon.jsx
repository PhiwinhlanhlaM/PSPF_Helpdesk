// Icon set - small inline SVGs used across the app.
function Icon({ name, size = 16, stroke = 1.6, ...rest }) {
  const props = {
    width: size, height: size, viewBox: "0 0 24 24",
    fill: "none", stroke: "currentColor", strokeWidth: stroke,
    strokeLinecap: "round", strokeLinejoin: "round",
    ...rest,
  };
  switch (name) {
    case "check":   return <svg {...props}><path d="M4 12.5l5 5L20 6"/></svg>;
    case "check-circle": return <svg {...props}><circle cx="12" cy="12" r="9"/><path d="M8 12.5l3 3 5-6"/></svg>;
    case "x":       return <svg {...props}><path d="M6 6l12 12M18 6L6 18"/></svg>;
    case "x-circle":return <svg {...props}><circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6"/></svg>;
    case "clock":   return <svg {...props}><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>;
    case "chevron-down": return <svg {...props}><path d="M6 9l6 6 6-6"/></svg>;
    case "chevron-right":return <svg {...props}><path d="M9 6l6 6-6 6"/></svg>;
    case "chevron-left": return <svg {...props}><path d="M15 6l-6 6 6 6"/></svg>;
    case "plus":    return <svg {...props}><path d="M12 5v14M5 12h14"/></svg>;
    case "search":  return <svg {...props}><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>;
    case "filter":  return <svg {...props}><path d="M3 5h18M6 12h12M10 19h4"/></svg>;
    case "user":    return <svg {...props}><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-7 8-7s8 3 8 7"/></svg>;
    case "calendar":return <svg {...props}><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 9h18M8 3v4M16 3v4"/></svg>;
    case "edit":    return <svg {...props}><path d="M4 20h4l10-10-4-4L4 16v4z"/><path d="M14 6l4 4"/></svg>;
    case "upload":  return <svg {...props}><path d="M12 17V5M6 11l6-6 6 6"/><path d="M5 19h14"/></svg>;
    case "download":return <svg {...props}><path d="M12 5v12M6 11l6 6 6-6"/><path d="M5 21h14"/></svg>;
    case "file":    return <svg {...props}><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/></svg>;
    case "key":     return <svg {...props}><circle cx="8" cy="14" r="4"/><path d="M11 11l9-9M17 5l3 3M14 8l3 3"/></svg>;
    case "shield":  return <svg {...props}><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6l8-3z"/></svg>;
    case "bank":    return <svg {...props}><path d="M3 10l9-6 9 6"/><path d="M5 10v9M19 10v9M9 10v9M15 10v9M3 21h18"/></svg>;
    case "phone":   return <svg {...props}><path d="M5 4h4l2 5-3 2a12 12 0 006 6l2-3 5 2v4a2 2 0 01-2 2A17 17 0 013 6a2 2 0 012-2z"/></svg>;
    case "door":    return <svg {...props}><path d="M5 21V4a1 1 0 011-1h12a1 1 0 011 1v17"/><path d="M3 21h18M15 12v1"/></svg>;
    case "archive": return <svg {...props}><rect x="3" y="4" width="18" height="4" rx="1"/><path d="M5 8v11a1 1 0 001 1h12a1 1 0 001-1V8M10 12h4"/></svg>;
    case "scale":   return <svg {...props}><path d="M12 3v18M5 7h14M6 7l-3 7a4 4 0 008 0L8 7M18 7l-3 7a4 4 0 008 0l-3-7"/></svg>;
    case "logout":  return <svg {...props}><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg>;
    case "menu":    return <svg {...props}><path d="M3 6h18M3 12h18M3 18h18"/></svg>;
    case "bell":    return <svg {...props}><path d="M6 8a6 6 0 0112 0c0 7 3 8 3 8H3s3-1 3-8M10 21a2 2 0 004 0"/></svg>;
    case "info":    return <svg {...props}><circle cx="12" cy="12" r="9"/><path d="M12 8h.01M11 12h1v5h1"/></svg>;
    case "alert":   return <svg {...props}><path d="M12 3l10 18H2L12 3z"/><path d="M12 10v5M12 18h.01"/></svg>;
    case "sparkle": return <svg {...props}><path d="M12 3l2 6 6 2-6 2-2 6-2-6-6-2 6-2 2-6z"/></svg>;
    case "shield-check": return <svg {...props}><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6l8-3z"/><path d="M9 12l2 2 4-4"/></svg>;
    case "pen":     return <svg {...props}><path d="M3 21l3-1 12-12-2-2L4 18l-1 3z"/><path d="M14 6l4 4"/></svg>;
    case "trash":   return <svg {...props}><path d="M4 7h16M9 7V4h6v3M6 7l1 13a2 2 0 002 2h6a2 2 0 002-2l1-13"/></svg>;
    case "undo":    return <svg {...props}><path d="M9 14l-4-4 4-4"/><path d="M5 10h9a5 5 0 010 10h-3"/></svg>;
    case "lock":    return <svg {...props}><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V7a4 4 0 018 0v4"/></svg>;
    case "link":    return <svg {...props}><path d="M10 14a4 4 0 015 0l3-3a4 4 0 00-6-6l-1 1M14 10a4 4 0 01-5 0l-3 3a4 4 0 006 6l1-1"/></svg>;
    case "server":  return <svg {...props}><rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01M7 16.5h.01"/></svg>;
    case "logo-leaf": return <svg {...props}><path d="M12 3v18M12 8c-2-3-5-3-7-1 0 3 2 5 7 5M12 8c2-3 5-3 7-1 0 3-2 5-7 5M12 14c-2-3-5-3-7-1 0 3 2 5 7 5M12 14c2-3 5-3 7-1 0 3-2 5-7 5"/></svg>;
    default:        return <svg {...props}><circle cx="12" cy="12" r="2"/></svg>;
  }
}

window.Icon = Icon;

import { animate, inView } from "motion"

export function initAgentNetwork(root) {
    const layout = root.querySelector("[data-network-layout]")
    const svg = root.querySelector("[data-network-lines]")
    const hub = root.querySelector("[data-network-hub]")
    const groups = ["agents", "records"].map(name => root.querySelector(`[data-network-${name}]`))
    const highlight = root.querySelector("[data-network-highlight]")
    const status = root.querySelector("[data-network-status]")
    const defaultStatus = status.textContent
    const nodes = [...root.querySelectorAll("[data-network-node]")]
    const reducedMotion = matchMedia("(prefers-reduced-motion: reduce)")
    const tokens = getComputedStyle(root)
    const seconds = token => {
        const value = tokens.getPropertyValue(token).trim()
        return parseFloat(value) / (value.endsWith("ms") ? 1000 : 1)
    }
    const duration = seconds("--duration-base")
    const entranceDuration = seconds("--duration-slow")
    const ease = tokens.getPropertyValue("--ease-out-expo").match(/[\d.]+/g).map(Number)
    const connections = nodes.map(node => {
        const paths = ["text-gray-500", "text-primary-600 dark:text-primary-400"].map((className, index) => {
            const path = document.createElementNS("http://www.w3.org/2000/svg", "path")
            path.setAttribute("class", className)
            path.setAttribute("stroke", "currentColor")
            path.setAttribute("stroke-width", index ? "1.5" : "1")
            path.setAttribute("pathLength", "1")
            if (index) {
                path.dataset.networkAccent = node.dataset.networkNode
                path.style.strokeDasharray = "1"
                path.style.opacity = "0"
            }
            svg.append(path)
            return path
        })
        return { node, incoming: node.dataset.networkSide === "agent", base: paths[0], accent: paths[1] }
    })
    const incoming = connections.filter(connection => connection.incoming)
    const outgoing = connections.filter(connection => !connection.incoming)
    let horizontal = false
    let visible = false
    let active = null
    let hovered = null
    let focused = null
    let tapped = null
    let pointerStart = null
    let dragged = false
    let animation = null
    let entered = false

    function nodeFor(target) {
        const node = target?.closest?.("[data-network-node]")
        return node && root.contains(node) ? node : null
    }

    function draw(animated = false) {
        animation?.stop()
        animation = null
        const selected = connections.filter(connection => active && (horizontal
            ? connection.node === active || connection.node.dataset.networkSide !== active.dataset.networkSide
            : connection === incoming[0] || connection === outgoing[0]))

        connections.forEach(connection => {
            connection.accent.style.opacity = selected.includes(connection) ? "1" : "0"
            connection.accent.style.strokeDashoffset = "0"
        })
        highlight.style.opacity = active ? "1" : "0"

        if (!animated || !active || reducedMotion.matches || !visible || document.hidden) return

        const render = progress => {
            selected.forEach(connection => {
                const phase = connection.incoming ? Math.min(1, progress / 0.65) : Math.max(0, (progress - 0.25) / 0.75)
                connection.accent.style.strokeDashoffset = String(1 - phase)
            })
        }
        render(0)
        animation = animate(0, 1, { duration, ease, onUpdate: render })
    }

    function enter() {
        if (entered || active || reducedMotion.matches || document.hidden) return
        entered = true
        const paths = connections.filter(connection => horizontal || connection === incoming[0] || connection === outgoing[0])
        const render = progress => {
            paths.forEach(connection => {
                const phase = Math.max(0, Math.min(1, connection.incoming ? progress / 0.7 : (progress - 0.2) / 0.8))
                connection.accent.style.strokeDashoffset = String(1 - Math.min(1, phase / 0.65))
                connection.accent.style.opacity = String(Math.sin(phase * Math.PI) * 0.8)
            })
        }
        render(0)
        animation = animate(0, 1, { duration: entranceDuration, ease, onUpdate: render, onComplete: () => draw() })
    }

    function preview(node) {
        if (active === node) return
        active = node
        nodes.forEach(item => {
            item.toggleAttribute("data-active", item === node)
            item.setAttribute("aria-pressed", String(item === node))
        })
        status.textContent = node?.dataset.networkDescription ?? defaultStatus
        draw(true)
    }

    function clear() {
        hovered = focused = tapped = pointerStart = null
        preview(null)
        draw()
    }

    function measure() {
        if (document.hidden) return
        const bounds = layout.getBoundingClientRect()
        const center = hub.getBoundingClientRect()
        const groupBounds = groups.map(group => group.getBoundingClientRect())
        const rectangles = nodes.map(node => node.getBoundingClientRect())
        horizontal = getComputedStyle(layout).gridTemplateColumns.split(" ").length > 1
        const paths = connections.map((connection, index) => {
            const group = connection.incoming ? incoming : outgoing
            const rect = horizontal ? rectangles[index] : groupBounds[connection.incoming ? 0 : 1]
            const spread = (group.indexOf(connection) / (group.length - 1) - 0.5) * 0.6
            const start = horizontal
                ? [connection.incoming ? rect.right : center.right, connection.incoming ? rect.top + rect.height / 2 : center.top + center.height * (0.5 + spread)]
                : [connection.incoming ? rect.left + rect.width / 2 : center.left + center.width / 2, connection.incoming ? rect.bottom : center.bottom]
            const end = horizontal
                ? [connection.incoming ? center.left : rect.left, connection.incoming ? center.top + center.height * (0.5 + spread) : rect.top + rect.height / 2]
                : [connection.incoming ? center.left + center.width / 2 : rect.left + rect.width / 2, connection.incoming ? center.top : rect.top]
            const [x1, y1] = [start[0] - bounds.left, start[1] - bounds.top]
            const [x2, y2] = [end[0] - bounds.left, end[1] - bounds.top]
            const middle = (x1 + x2) / 2
            return horizontal
                ? `M ${x1} ${y1} C ${middle} ${y1}, ${middle} ${y2}, ${x2} ${y2}`
                : `M ${x1} ${y1} L ${x2} ${y2}`
        })

        svg.setAttribute("viewBox", `0 0 ${bounds.width} ${bounds.height}`)
        connections.forEach((connection, index) => {
            const hidden = !horizontal && connection !== incoming[0] && connection !== outgoing[0]
            for (const path of [connection.base, connection.accent]) {
                path.setAttribute("d", paths[index])
                path.style.display = hidden ? "none" : ""
            }
        })
        draw()
    }

    root.addEventListener("pointerover", event => {
        if (event.pointerType === "touch") return
        const node = nodeFor(event.target)
        if (!node || node === hovered) return
        hovered = node
        tapped = null
        preview(node)
    })
    root.addEventListener("pointerout", event => {
        if (event.pointerType === "touch") return
        const next = nodeFor(event.relatedTarget)
        if (next === nodeFor(event.target)) return
        hovered = next
        preview(hovered ?? focused)
    })
    root.addEventListener("focusin", event => {
        const node = nodeFor(event.target)
        if (!node?.matches(":focus-visible")) return
        focused = node
        hovered = tapped = null
        preview(node)
    })
    root.addEventListener("focusout", () => {
        focused = tapped = null
        preview(hovered)
    })
    document.addEventListener("pointerdown", event => {
        const node = nodeFor(event.target)
        dragged = false
        focused = null
        if (!node) {
            clear()
            return
        }
        pointerStart = { node, x: event.clientX, y: event.clientY }
    })
    document.addEventListener("pointerup", event => {
        if (!pointerStart) return
        dragged = nodeFor(event.target) !== pointerStart.node || Math.hypot(event.clientX - pointerStart.x, event.clientY - pointerStart.y) > 8
        pointerStart = null
        if (dragged) clear()
    })
    document.addEventListener("pointercancel", clear)
    root.addEventListener("click", event => {
        const node = nodeFor(event.target)
        if (!node || dragged) return
        if (event.pointerType === "mouse" && event.detail > 0) {
            preview(node)
            return
        }
        tapped = tapped === node ? null : node
        hovered = focused = null
        preview(tapped)
    })
    root.addEventListener("keydown", event => {
        if (event.key === "Escape") {
            event.preventDefault()
            clear()
        }
    })

    measure()
    new ResizeObserver(() => { if (visible) measure() }).observe(layout)
    document.fonts.ready.then(measure)
    nodes.forEach(node => { node.disabled = false })

    inView(root, () => {
        visible = true
        measure()
        enter()
        return () => {
            visible = false
            clear()
        }
    }, { amount: 0.3 })
    reducedMotion.addEventListener("change", () => draw())
    document.addEventListener("visibilitychange", () => document.hidden ? clear() : measure())
    window.addEventListener("blur", clear)
    window.addEventListener("pagehide", clear)
}

(function (global) {
  'use strict'

  var StickyVideo = function (containerId) {
    this.containerId = containerId || 'sticky-container'
    this.container = document.getElementById(this.containerId)
    this.initialize()
  }

  StickyVideo.addClass = addClass
  StickyVideo.removeClass = removeClass
  StickyVideo.hasClass = hasClass
  StickyVideo.wrap = wrap
  StickyVideo.insertAfter = insertAfter

  StickyVideo.prototype.capturePlaceholderHeight = capturePlaceholderHeight
  StickyVideo.prototype.getVideoElement = getVideoElement
  StickyVideo.prototype.isVideoPlaying = isVideoPlaying
  StickyVideo.prototype.applyScrollState = applyScrollState
  StickyVideo.prototype.releaseSticky = releaseSticky
  StickyVideo.prototype.elementInViewport = elementInViewport
  StickyVideo.prototype.initialize = initialize

  function getVideoElement () {
    if (!this.container) return null
    return this.container.querySelector('video')
  }

  function isVideoPlaying () {
    var video = this.getVideoElement()
    if (!video) return false
    return !video.paused && !video.ended
  }

  function capturePlaceholderHeight (wrapDiv) {
    if (this._placeholderHeight) return
    var height = this.container.offsetHeight
    if (!height && wrapDiv) {
      height = Math.round(wrapDiv.getBoundingClientRect().height)
    }
    if (height > 0) {
      this._placeholderHeight = height
    }
  }

  function applyScrollState (wrapDiv) {
    var inViewport = this.elementInViewport(wrapDiv)
    var wasSticky = StickyVideo.hasClass(wrapDiv, 'sticky-container_sticky')
    var wasVisible = StickyVideo.hasClass(wrapDiv, 'sticky-container_sticky-visible')
    var shouldStick = !inViewport && this.isVideoPlaying()

    if (shouldStick) {
      if (this._hideTimer) {
        clearTimeout(this._hideTimer)
        this._hideTimer = null
      }
      if (!wasSticky) {
        this.capturePlaceholderHeight(wrapDiv)
      }
      if (this._placeholderHeight) {
        wrapDiv.style.height = this._placeholderHeight + 'px'
      }
      StickyVideo.removeClass(wrapDiv, 'sticky-container_in-content')
      StickyVideo.addClass(wrapDiv, 'sticky-container_sticky')
      if (!wasVisible) {
        StickyVideo.removeClass(wrapDiv, 'sticky-container_sticky-visible')
        window.requestAnimationFrame(function () {
          window.requestAnimationFrame(function () {
            StickyVideo.addClass(wrapDiv, 'sticky-container_sticky-visible')
          })
        })
      }
      return
    }

    if (wasSticky || wasVisible) {
      this.releaseSticky(wrapDiv, wasSticky, wasVisible && !inViewport)
    }
  }

  function releaseSticky (wrapDiv, wasSticky, animateOut) {
    var that = this

    function finish () {
      StickyVideo.removeClass(wrapDiv, 'sticky-container_sticky-visible')
      StickyVideo.removeClass(wrapDiv, 'sticky-container_sticky')
      StickyVideo.addClass(wrapDiv, 'sticky-container_in-content')
      wrapDiv.style.height = ''
      that._placeholderHeight = null
      that._hideTimer = null

      if (wasSticky) {
        window.requestAnimationFrame(function () {
          var rect = wrapDiv.getBoundingClientRect()
          var headerOffset = 56
          if (rect.top < headerOffset && window.scrollY < 600) {
            window.scrollTo(0, Math.max(0, window.scrollY + rect.top - headerOffset))
          }
        })
      }
    }

    if (that._hideTimer) {
      clearTimeout(that._hideTimer)
      that._hideTimer = null
    }

    if (animateOut) {
      StickyVideo.removeClass(wrapDiv, 'sticky-container_sticky-visible')
      that._hideTimer = setTimeout(finish, 320)
      return
    }

    finish()
  }
  function addClass (elements, className) {
    if (hasClass(elements, className)) return
    if (!elements) { return }
    if (typeof elements === 'string') {
      elements = document.querySelectorAll(elements)
    } else if (elements.tagName) { elements = [elements] }
    for (var i = 0; i < elements.length; i++) {
      if ((' ' + elements[i].className + ' ').indexOf(' ' + className + ' ') < 0) {
        elements[i].className += ' ' + className
      }
    }
  }
  function removeClass (elements, className) {
    if (!hasClass(elements, className)) return
    if (!elements) { return }
    if (typeof elements === 'string') {
      elements = document.querySelectorAll(elements)
    } else if (elements.tagName) { elements = [elements] }
    var reg = new RegExp('(^| )' + className + '($| )', 'g')
    for (var i = 0; i < elements.length; i++) {
      elements[i].className = elements[i].className.replace(reg, '')
    }
  }
  function hasClass (element, className) {
    return new RegExp('(\\s|^)' + className + '(\\s|$)').test(element.className)
  }
  function wrap (toWrap, wrapper) {
    return wrapper.appendChild(toWrap)
  }
  function insertAfter (el, referenceNode) {
    referenceNode.parentNode.insertBefore(el, referenceNode.nextSibling)
  }
  function elementInViewport (el) {
    var rect = el.getBoundingClientRect()
    return (rect.top > (el.offsetHeight * -1))
  }
  function initialize () {
    if (!this.container) return
    var that = this
    var wrapDiv = document.createElement('div')
    var scrollTicking = false

    function onWindowScroll () {
      that.applyScrollState(wrapDiv)
    }

    function onWindowResize () {
      if (StickyVideo.hasClass(wrapDiv, 'sticky-container_in-content')) {
        wrapDiv.style.height = ''
        that._placeholderHeight = null
      }
    }
    function onWindowScrollRaf () {
      if (scrollTicking) return
      scrollTicking = true
      if (window.requestAnimationFrame) {
        window.requestAnimationFrame(function () {
          onWindowScroll()
          scrollTicking = false
        })
      } else {
        setTimeout(function () {
          onWindowScroll()
          scrollTicking = false
        }, 100)
      }
    }

    wrapDiv.className = 'sticky-container__wrap'
    this.container.parentElement.insertBefore(wrapDiv, this.container)
    StickyVideo.insertAfter(wrapDiv, that.container)
    StickyVideo.wrap(that.container, wrapDiv)
    StickyVideo.addClass(wrapDiv, 'sticky-container_in-content')
    StickyVideo.addClass(that.container, 'sticky-container__video')

    function onPlaybackChange () {
      that.applyScrollState(wrapDiv)
    }

    var video = that.getVideoElement()
    if (video) {
      video.addEventListener('play', onPlaybackChange)
      video.addEventListener('playing', onPlaybackChange)
      video.addEventListener('pause', onPlaybackChange)
      video.addEventListener('ended', onPlaybackChange)
    }

    if (window.addEventListener) {
      window.addEventListener('scroll', onWindowScrollRaf, { passive: true })
      window.addEventListener('resize', onWindowResize, { passive: true })
    } else {
      window.onscroll = onWindowScroll
      window.onresize = onWindowResize
    }
  }

  // AMD support
  if (typeof define === 'function' && define.amd) {
    define(function () { return StickyVideo })
    // CommonJS and Node.js module support.
  } else if (typeof exports !== 'undefined') {
    // Support Node.js specific `module.exports` (which can be a function)
    if (typeof module !== 'undefined' && module.exports) {
      exports = module.exports = StickyVideo
    }
    // But always support CommonJS module 1.1.1 spec (`exports` cannot be a function)
    exports.StickyVideo = StickyVideo
  } else {
    global.StickyVideo = StickyVideo
  }
})(this)

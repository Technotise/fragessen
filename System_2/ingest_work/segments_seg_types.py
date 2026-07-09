#!/usr/bin/env python3
# -*- coding: utf-8 -*-

from __future__ import annotations
from dataclasses import dataclass
from typing import List


@dataclass
class TopItem:
    key: str
    title: str


@dataclass
class Candidate:
    page: int          # 0-based page index
    char: int          # 0-based char index inside OFFSET page text
    score: float
    method: str


@dataclass
class LayoutLine:
    text: str
    bold_ratio: float
    max_size: float


@dataclass
class RuleAnchor:
    page: int          # 0-based page index
    y: float           # y position of the horizontal rule
    x0: float
    x1: float
    header_text: str   # text directly above the rule (normalized)


class PageLayoutIndex:
    def __init__(self, lines: List[LayoutLine], median_size: float):
        self.lines = lines
        self.median_size = median_size